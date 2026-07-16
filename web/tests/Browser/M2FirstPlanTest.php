<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Households\CreateHouseholdPerson;
use App\Actions\Households\RecordConstraint;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\ConstraintKind;
use App\Enums\MealSlotKind;
use App\Enums\MessageRole;
use App\Models\MealSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

it('creates a first plan and continues its conversation in a real browser', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $person = $team->people()->sole();
    ChefAgent::fake([
        new ToolCall('slot-call', 'CreatePlanMealSlot', [
            'date' => today()->toDateString(),
            'kind' => 'dinner',
            'participant_ids' => [$person->id],
        ]),
        fn () => new ToolCall('proposal-call', 'CreateMealProposal', [
            'title' => 'Satay chicken',
            'meal_slot_id' => MealSlot::query()->sole()->id,
            'summary' => 'Quick chicken satay with rice and broccoli.',
            'estimated_minutes' => 35,
            'estimated_cost' => 18,
        ]),
        'I have added satay chicken for you to review.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    $page = visit('/dashboard')->on()->desktop()
        ->assertSee('Welcome to Chef')
        ->click('Start a plan')
        ->assertSee('Who are we feeding')
        ->type('textarea[aria-label="Message Chef"]', 'Please plan satay chicken for our first dinner.')
        ->click('[data-testid="send-message"]')
        ->assertSee('I have added satay chicken for you to review.')
        ->assertSee('Satay chicken')
        ->assertSee('Accept')
        ->click('Accept')
        ->assertSee('Satay chicken');

    $page->assertNoJavaScriptErrors();
});

it('keeps the planning workspace usable at a narrow mobile width', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))
        ->resize(390, 844)
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertSee('Household truth')
        ->assertNoJavaScriptErrors();
});

it('resets conversation state when navigating between plans', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $first = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay(), 'First plan');
    $second = app(StartMealPlan::class)->handle($team, $user, today()->addDays(2), today()->addDays(3), 'Second plan');
    $first->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => 'Only the first plan contains satay.',
    ]);
    $second->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => 'Only the second plan contains katsu.',
    ]);
    $this->actingAs($user);

    visit(route('meal-plans.show', $first))->on()->desktop()
        ->assertSee('Only the first plan contains satay.')
        ->click('a[href="'.route('meal-plans.show', $second, absolute: false).'"]')
        ->assertSee('Only the second plan contains katsu.')
        ->assertDontSee('Only the first plan contains satay.')
        ->assertNoJavaScriptErrors();
});

it('shows safety provenance and can link an invitation to a household person', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $conversation = $plan->conversations->first();
    $message = app(CreateUserMessage::class)->handle($conversation, $user, 'Tahlia has a severe peanut allergy.', (string) Str::uuid());
    $tahlia = app(CreateHouseholdPerson::class)->handle($team, $user, 'Tahlia', $message);
    app(RecordConstraint::class)->handle($team, $user, ConstraintKind::Allergy, 'Peanuts', confirmationMessage: $message, person: $tahlia, severity: 'severe');
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Confirmed by Daniel')
        ->assertSee('Tahlia has a severe peanut allergy.')
        ->assertPresent('select[aria-label="Link invitation to household member"]')
        ->assertSee('Tahlia')
        ->assertNoJavaScriptErrors();
});

it('keeps reject and move actions available as direct controls', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $first = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    app(CreateMealSlot::class)->handle($plan, $user, today()->addDay(), MealSlotKind::Dinner, $team->people);
    app(ProposeMeal::class)->handle($plan, $user, 'Mushroom pasta', $first);
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Mushroom pasta')
        ->click('Reject')
        ->assertDontSee('Mushroom pasta')
        ->assertNoJavaScriptErrors();
});
