<?php

use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;

/** @param array<int, mixed> $responses */
function useBrowserResumableChefGateway(array $responses): void
{
    $manager = app(AiManager::class);
    $forgetRegisteredFake = Closure::bind(function (): void {
        unset($this->fakeAgentGateways[ChefAgent::class]);
    }, $manager, $manager);
    $forgetRegisteredFake();
    $manager->textProvider('openai')->useTextGateway(
        (new FakeTextGateway($responses))->preventStrayPrompts(),
    );
}

it('captures response feedback and carries a completed plan into confirmation feedback', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Feedback family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people()->get(),
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Custom,
        title: 'Chicken katsu curry with rice',
        servings: 2,
    );
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Your plan is ready to approve')
        ->click('button[aria-label="Mark this response unhelpful"]')
        ->assertSee('What could Chef improve?')
        ->click('Changed the wrong thing')
        ->type('textarea[placeholder="Tell us what happened…"]', 'Chef assigned this to the wrong person.')
        ->pressAndWaitFor('Save feedback')
        ->pressAndWaitFor('Approve plan & prepare recipes')
        ->assertSee('Plan and recipes are ready')
        ->assertSee('How did planning feel?')
        ->click('button[aria-label="Planning was helpful"]')
        ->assertSee('What worked well?')
        ->type('textarea[placeholder="Tell us what happened…"]', 'The plan understood our preferences.')
        ->pressAndWaitFor('Save feedback')
        ->assertDontSee('How did planning feel?')
        ->pressAndWaitFor('View recipes')
        ->assertSee('Chicken katsu curry with rice')
        ->assertNoJavaScriptErrors();

    $feedback = $plan->conversations->firstOrFail()->feedback()->whereNotNull('message_id')->sole();
    expect($feedback->rating->value)->toBe('unhelpful')
        ->and($feedback->reasons)->toBe(['wrong_action'])
        ->and($feedback->comment)->toBe('Chef assigned this to the wrong person.');

    $checkpoint = $plan->conversations->firstOrFail()->feedback()->whereNull('message_id')->sole();
    expect($checkpoint->rating->value)->toBe('helpful')
        ->and($checkpoint->comment)->toBe('The plan understood our preferences.');
});

it('rejects the persisted sdk approval card at desktop and narrow widths', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Approval family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people()->get(),
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Custom,
        title: 'Satay chicken',
        servings: 2,
    );
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);

    $firstRevision = $plan->refresh()->revision;
    useBrowserResumableChefGateway([
        new ToolCall('browser-reject-call', 'ConfirmPlan', ['plan_revision' => $firstRevision]),
        'No problem — the plan is unchanged.',
    ]);
    $conversation = $plan->conversations()->sole();
    $this->actingAs($user)->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Review this plan.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Chef is waiting for your decision')
        ->assertSee('Approve plan')
        ->assertSee('Keep editing')
        ->assertNoAccessibilityIssues()
        ->resize(390, 844)
        ->assertSee('Approve plan')
        ->pressAndWaitFor('Keep editing')
        ->assertSee('No problem — the plan is unchanged.')
        ->wait(2)
        ->assertNotPresent('button:has-text("Keep editing")')
        ->assertSee('Approve plan & prepare recipes')
        ->assertNoJavaScriptErrors();

    expect($plan->refresh()->planning_confirmed_at)->toBeNull();
});

it('approves the persisted sdk approval card through the resumed conversation', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Approval family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people()->get(),
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Custom,
        title: 'Satay chicken',
        servings: 2,
    );
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);

    $revision = $plan->refresh()->revision;
    useBrowserResumableChefGateway([
        new ToolCall('browser-approve-call', 'ConfirmPlan', ['plan_revision' => $revision]),
        'The plan is confirmed and its recipes are being prepared.',
    ]);
    $conversation = $plan->conversations()->sole();
    $this->actingAs($user)->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Review this plan.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Chef is waiting for your decision')
        ->pressAndWaitFor('Approve plan')
        ->wait(2)
        ->assertNotPresent('[data-plan-approval]')
        ->assertNotPresent('button:has-text("Keep editing")')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    expect($plan->refresh()->planning_confirmed_at)->not->toBeNull();
});
