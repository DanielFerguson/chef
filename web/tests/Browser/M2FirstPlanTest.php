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

it('renders persisted and streamed assistant markdown as readable content', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $plan->conversations->first()->messages()->create([
        'team_id' => $team->id,
        'role' => MessageRole::Assistant,
        'content' => "## Dinner ideas\n\n- **Satay chicken**\n- Pork katsu\n\n| Day | Time |\n| --- | ---: |\n| Monday | 30 min |\n\nUse `jasmine rice`.",
    ]);
    ChefAgent::fake([
        "## Updated options\n\n1. **Butter chicken**\n2. Beef noodles\n\nReady in `35 minutes`.",
    ])->preventStrayPrompts();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertPresent('[data-message-role="assistant"] h2')
        ->assertPresent('[data-message-role="assistant"] ul')
        ->assertPresent('[data-message-role="assistant"] table')
        ->assertPresent('[data-message-role="assistant"] code')
        ->assertDontSee('**Satay chicken**')
        ->type('textarea[aria-label="Message Chef"]', 'Please update those options.')
        ->click('[data-testid="send-message"]')
        ->assertSee('Updated options')
        ->assertPresent('[data-message-role="assistant"] ol')
        ->assertDontSee('**Butter chicken**')
        ->assertNoJavaScriptErrors();
});

it('keeps a long conversation at the live edge with the composer in view', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->first();

    foreach (range(1, 12) as $index) {
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::User,
            'user_id' => $user->id,
            'content' => "Planning note {$index} with enough detail to make this transcript scroll.",
        ]);
        $conversation->messages()->create([
            'team_id' => $team->id,
            'role' => MessageRole::Assistant,
            'content' => "Chef response {$index} with a useful recommendation for the plan.",
        ]);
    }

    ChefAgent::fake([
        'The newest recommendation stays visible at the live edge.',
    ])->preventStrayPrompts();
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->wait(1)
        ->assertPresent('[data-slot="message-scroller"]')
        ->assertPresent('[data-slot="message"]')
        ->assertPresent('[data-slot="bubble"]')
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return viewport.scrollHeight > viewport.clientHeight;
        }")
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return Math.abs(viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop) < 4;
        }")
        ->assertScript('() => document.documentElement.scrollHeight <= window.innerHeight + 4')
        ->assertScript("() => {
            const composer = document.querySelector('textarea[aria-label=\"Message Chef\"]');
            const bounds = composer.getBoundingClientRect();
            return !composer.disabled && bounds.top >= 0 && bounds.bottom <= window.innerHeight;
        }")
        ->type('textarea[aria-label="Message Chef"]', 'What should we cook next?')
        ->click('[data-testid="send-message"]')
        ->assertSee('The newest recommendation stays visible at the live edge.')
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            return Math.abs(viewport.scrollHeight - viewport.clientHeight - viewport.scrollTop) < 4;
        }")
        ->assertNoJavaScriptErrors();
});

it('keeps the planning workspace usable at a narrow mobile width', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))
        ->resize(390, 844)
        ->assertPresent('[data-slot="message-scroller"]')
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
