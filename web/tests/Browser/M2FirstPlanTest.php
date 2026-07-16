<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Models\MealSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
