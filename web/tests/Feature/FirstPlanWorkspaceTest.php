<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\MovePlannedMeal;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\RejectMealProposal;
use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeamForUser;
use App\Actions\Teams\InviteUserToTeam;
use App\Ai\Agents\ChefAgent;
use App\Enums\ConstraintKind;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

it('creates and resumes a meal plan workspace', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');

    $response = $this->actingAs($user)->post(route('meal-plans.store'), [
        'title' => 'Next few days',
        'starts_on' => '2026-07-20',
        'ends_on' => '2026-07-23',
    ]);

    $plan = $team->mealPlans()->sole();
    $response->assertRedirect(route('meal-plans.show', $plan));

    $this->get(route('meal-plans.show', $plan))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('meal-plans/show')
            ->where('workspace.plan.title', 'Next few days')
            ->where('workspace.household.name', 'The Test Kitchen')
            ->has('workspace.conversation.messages', 1));
});

it('adds a meal slot through the workspace route', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));

    $this->actingAs($user)->post(route('meal-plans.slots.store', $plan), [
        'date' => today()->toDateString(),
        'kind' => 'dinner',
        'participant_ids' => $team->people()->pluck('id')->all(),
    ])->assertRedirect();

    expect($plan->slots()->sole()->kind)->toBe(MealSlotKind::Dinner);
});

it('streams and persists an attributed assistant response without a live request', function () {
    ChefAgent::fake(['Who will be home on Thursday?'])->preventStrayPrompts();
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->first();

    $response = $this->actingAs($user)->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Tahlia is away Thursday.',
        'client_message_id' => fake()->uuid(),
    ]);

    $response->assertOk();
    expect($response->streamedContent())->toContain('"type":"delta"')
        ->and($conversation->messages()->count())->toBe(3)
        ->and($conversation->messages()->where('role', 'user')->sole()->user_id)->toBe($user->id)
        ->and($conversation->messages()->reorder()->latest('id')->first()->content)->toBe('Who will be home on Thursday?');
});

it('turns a conversational request into a reviewable structured meal proposal', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    $conversation = $plan->conversations->first();

    ChefAgent::fake([
        new ToolCall('proposal-call', 'CreateMealProposal', [
            'title' => 'Satay chicken',
            'meal_slot_id' => $slot->id,
            'summary' => 'Quick chicken satay with rice and broccoli.',
            'estimated_minutes' => 35,
            'estimated_cost' => 18,
        ]),
        'I have added satay chicken for you to review.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Please suggest satay chicken for the first dinner.',
        'client_message_id' => fake()->uuid(),
    ]);

    $response->assertOk();
    expect($response->streamedContent())->toContain('"type":"delta"')
        ->and($conversation->messages()->reorder()->latest('id')->first()->content)->toBe('I have added satay chicken for you to review.')
        ->and($plan->proposals()->sole()->title)->toBe('Satay chicken')
        ->and($plan->proposals()->sole()->message_id)->not->toBeNull()
        ->and($slot->plannedMeal)->toBeNull();
});

it('lets a second family member make an attributed conversation change', function () {
    ChefAgent::fake(['Got it — I will plan one serving.'])->preventStrayPrompts();
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Tahlia']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Shared table');
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay());
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, $member->email);
    app(AcceptTeamInvitation::class)->handle($invitation, $member);

    $this->actingAs($member)->post(route('conversations.messages.stream', $plan->conversations->first()), [
        'content' => 'I am away Thursday.',
        'client_message_id' => fake()->uuid(),
    ])->assertOk();

    expect($plan->conversations->first()->messages()->where('role', 'user')->sole()->user_id)->toBe($member->id);
});

it('keeps proposals reviewable and supports accept replace reject and move', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $people = $team->people;
    $firstSlot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $people);
    $secondSlot = app(CreateMealSlot::class)->handle($plan, $user, today()->addDay(), MealSlotKind::Dinner, $people);

    $firstProposal = app(ProposeMeal::class)->handle($plan, $user, 'Satay chicken', $firstSlot);
    $selected = app(AcceptMealProposal::class)->handle($firstProposal, $user);
    expect($selected->title)->toBe('Satay chicken')
        ->and($firstProposal->refresh()->status)->toBe(MealProposalStatus::Accepted);

    $replacement = app(ProposeMeal::class)->handle($plan, $user, 'Butter chicken', $firstSlot);
    $selected = app(AcceptMealProposal::class)->handle($replacement, $user);
    expect($selected->title)->toBe('Butter chicken')
        ->and($firstProposal->refresh()->status)->toBe(MealProposalStatus::Replaced);

    app(MovePlannedMeal::class)->handle($selected, $secondSlot, $user);
    expect($selected->refresh()->meal_slot_id)->toBe($secondSlot->id);

    $rejected = app(ProposeMeal::class)->handle($plan, $user, 'Mushroom pasta', $firstSlot);
    app(RejectMealProposal::class)->handle($rejected, $user);
    expect($rejected->refresh()->status)->toBe(MealProposalStatus::Rejected);
});

it('never records an unconfirmed safety constraint', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');

    app(RecordConstraint::class)->handle(
        $team,
        $user,
        ConstraintKind::Allergy,
        'Peanuts',
        false,
    );
})->throws(ValidationException::class);

it('hides plan route bindings outside the active family', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Private family');
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay());
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->actingAs($outsider)->get("/meal-plans/{$plan->id}")->assertNotFound();
});
