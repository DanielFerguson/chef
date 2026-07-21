<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Households\CreateHouseholdPerson;
use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\RejectMealProposal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantStreamChunk;
use App\Enums\ConstraintKind;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

function m21Workspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Shared Table');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(3));
    $conversation = $plan->conversations->firstOrFail();

    return compact('user', 'team', 'plan', 'conversation');
}

function m21UserMessage(array $workspace, string $content = 'Tahlia is allergic to peanuts.'): Message
{
    return app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        $content,
        (string) Str::uuid(),
    );
}

it('creates a durable household person once from an attributed user message', function () {
    $workspace = m21Workspace();
    $message = m21UserMessage($workspace, 'Tahlia is planning with me.');

    $first = app(CreateHouseholdPerson::class)->handle($workspace['team'], $workspace['user'], 'Tahlia', $message);
    $again = app(CreateHouseholdPerson::class)->handle($workspace['team'], $workspace['user'], 'tahlia', $message);

    expect($again->is($first))->toBeTrue()
        ->and($workspace['team']->people()->whereRaw('lower(name) = ?', ['tahlia'])->count())->toBe(1)
        ->and($first->source_message_id)->toBe($message->id)
        ->and($first->created_by_user_id)->toBe($workspace['user']->id);
});

it('will not create a person from an assistant foreign-team or foreign-author message', function (string $case) {
    $workspace = m21Workspace();
    $message = m21UserMessage($workspace, 'Tahlia joins this plan.');

    if ($case === 'assistant') {
        $message->update(['role' => MessageRole::Assistant]);
    }

    if ($case === 'foreign-author') {
        $message->update(['user_id' => User::factory()->create()->id]);
    }

    if ($case === 'foreign-team') {
        $other = User::factory()->create();
        $message->update(['team_id' => app(CreateTeamForUser::class)->handle($other, 'Other')->id]);
    }

    app(CreateHouseholdPerson::class)->handle($workspace['team'], $workspace['user'], 'Tahlia', $message->fresh());
})->with(['assistant', 'foreign-author', 'foreign-team'])->throws(AuthorizationException::class);

it('links an invitation to an existing household person without duplicating them', function () {
    $workspace = m21Workspace();
    $person = app(CreateHouseholdPerson::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'Tahlia',
        m21UserMessage($workspace, 'Tahlia is part of our household.'),
    );
    $invitee = User::factory()->create(['email' => 'tahlia@example.test']);

    $this->actingAs($workspace['user'])->post(route('team-invitations.store'), [
        'email' => $invitee->email,
        'person_id' => $person->id,
    ])->assertRedirect();

    $invitation = $workspace['team']->invitations()->sole();
    $this->actingAs($invitee)->get(route('team-invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitation.person.name', 'Tahlia')
            ->where('invitation.matches_user', true));
    $this->actingAs($invitee)->post(route('team-invitations.accept', $invitation))->assertRedirect();
    $this->post(route('team-invitations.accept', $invitation))->assertSessionHasErrors('invitation');

    expect($workspace['team']->people()->count())->toBe(2)
        ->and($person->userLink()->sole()->user_id)->toBe($invitee->id)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

it('retains a human-verifiable source for safety constraints', function () {
    $workspace = m21Workspace();
    $message = m21UserMessage($workspace);
    $person = $workspace['team']->people()->sole();

    $constraint = app(RecordConstraint::class)->handle(
        team: $workspace['team'],
        user: $workspace['user'],
        kind: ConstraintKind::Allergy,
        subject: 'Peanuts',
        confirmationMessage: $message,
        person: $person,
    );

    expect($constraint->confirmation_message_id)->toBe($message->id)
        ->and($constraint->confirmationMessage->content)->toBe('Tahlia is allergic to peanuts.');

    $this->withoutVite()->actingAs($workspace['user'])->get(route('meal-plans.show', $workspace['plan']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.household.people.0.constraints.0.confirmation_message.content', 'Tahlia is allergic to peanuts.')
            ->where('workspace.household.people.0.constraints.0.confirmation_message.author.id', $workspace['user']->id));
});

it('lets Chef create people and attributed household truth through real SDK tools', function () {
    $workspace = m21Workspace();
    $clientId = (string) Str::uuid();
    ChefAgent::fake([
        new ToolCall('person-call', 'CreateHouseholdPerson', ['name' => 'Tahlia']),
        new ToolCall('preference-call', 'RecordHouseholdPreference', [
            'scope' => 'family', 'subject' => 'mushrooms', 'sentiment' => 'dislike', 'provenance' => 'stated', 'strength' => 5,
            'evidence_quote' => 'We dislike mushrooms',
        ]),
        new ToolCall('constraint-call', 'RecordSafetyConstraint', [
            'kind' => 'allergy', 'subject' => 'peanuts', 'severity' => 'severe',
        ]),
        'I saved those household details.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Tahlia plans with me. We dislike mushrooms and have a severe peanut allergy.',
        'client_message_id' => $clientId,
    ]);

    $response->streamedContent();

    expect($workspace['conversation']->messages()->reorder()->latest('id')->first()->content)->toBe('I saved those household details.')
        ->and($workspace['team']->people()->where('name', 'Tahlia')->count())->toBe(1)
        ->and($workspace['team']->preferences()->sole()->evidence)->toBe(['message_id' => $workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->id])
        ->and($workspace['team']->constraints()->sole()->confirmation_message_id)->toBe($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->id);
});

it('makes repeated slot tool calls from one turn idempotent', function () {
    $workspace = m21Workspace();
    $personId = $workspace['team']->people()->sole()->id;
    $arguments = [
        'date' => today()->toDateString(),
        'kind' => 'dinner',
        'participant_ids' => [$personId],
    ];
    ChefAgent::fake([
        new ToolCall('slot-call-1', 'CreatePlanMealSlot', $arguments),
        new ToolCall('slot-call-2', 'CreatePlanMealSlot', $arguments),
        'Dinner is on the plan.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Add dinner tonight.',
        'client_message_id' => (string) Str::uuid(),
    ]);

    $response->streamedContent();

    expect($workspace['conversation']->messages()->reorder()->latest('id')->first()->content)->toBe('Dinner is on the plan.')
        ->and($workspace['plan']->slots()->count())->toBe(1)
        ->and($workspace['plan']->slots()->sole()->source_message_id)->not->toBeNull();
});

it('executes inspect date-span and move tools against the same authorised plan', function () {
    $workspace = m21Workspace();
    $first = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $second = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Dinner, $workspace['team']->people);
    $proposal = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Satay chicken', $first);
    $planned = app(AcceptMealProposal::class)->handle($proposal, $workspace['user']);
    ChefAgent::fake([
        new ToolCall('team-inspect', 'InspectTeamContext', []),
        new ToolCall('plan-inspect', 'InspectMealPlan', []),
        new ToolCall('date-call', 'UpdatePlanDateSpan', [
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addDays(2)->toDateString(),
        ]),
        new ToolCall('move-call', 'MoveSelectedMeal', [
            'planned_meal_id' => $planned->id,
            'target_meal_slot_id' => $second->id,
        ]),
        'The plan is updated.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Inspect the plan, shorten it, and move dinner.',
        'client_message_id' => (string) Str::uuid(),
    ]);
    $response->streamedContent();

    expect($workspace['plan']->refresh()->ends_on->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($planned->refresh()->meal_slot_id)->toBe($second->id);
});

it('will not shrink a plan around an existing slot or update another family plan', function () {
    $workspace = m21Workspace();
    app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDays(3), MealSlotKind::Dinner, $workspace['team']->people);

    expect(fn () => app(UpdateMealPlanDateSpan::class)->handle($workspace['plan'], $workspace['user'], today(), today()->addDays(2)))
        ->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    expect(fn () => app(UpdateMealPlanDateSpan::class)->handle($workspace['plan'], $outsider, today(), today()->addDays(4)))
        ->toThrow(AuthorizationException::class);
});

it('supports authorised direct editing decisions and moves through HTTP controllers', function () {
    $workspace = m21Workspace();
    $person = $workspace['team']->people()->sole();
    $preference = app(RecordPreference::class)->handle($workspace['team'], $workspace['user'], 'Olives', PreferenceSentiment::Dislike, PreferenceProvenance::Stated, $person);
    $constraint = app(RecordConstraint::class)->handle($workspace['team'], $workspace['user'], ConstraintKind::Dietary, 'No alcohol', directlyConfirmed: true, person: $person);
    $first = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $second = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Dinner, $workspace['team']->people);
    $accepted = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Satay chicken', $first);
    $rejected = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Pork katsu', $second);
    $this->actingAs($workspace['user']);

    $this->put(route('preferences.update', $preference), ['subject' => 'Mushrooms', 'sentiment' => 'dislike', 'strength' => 5])->assertRedirect();
    $editedPreference = $workspace['team']->preferences()->where('subject', 'Mushrooms')->sole();
    $this->delete(route('preferences.destroy', $editedPreference))->assertRedirect();

    $this->put(route('constraints.update', $constraint), ['subject' => 'No cooking alcohol', 'details' => null, 'severity' => null, 'explicitly_confirmed' => true])->assertRedirect();
    $editedConstraint = $workspace['team']->constraints()->where('subject', 'No cooking alcohol')->sole();
    $this->delete(route('constraints.destroy', $editedConstraint))->assertRedirect();

    $this->put(route('meal-proposals.accept', $accepted))->assertRedirect();
    $this->put(route('meal-proposals.reject', $rejected))->assertRedirect();
    $planned = $workspace['plan']->plannedMeals()->where('meal_proposal_id', $accepted->id)->sole();
    $this->put(route('planned-meals.move', $planned), [
        'meal_slot_id' => $second->id,
        'expected_revision' => $workspace['plan']->refresh()->revision,
    ])->assertRedirect();

    expect($workspace['team']->preferences()->count())->toBe(0)
        ->and($workspace['team']->constraints()->count())->toBe(0)
        ->and($accepted->refresh()->status)->toBe(MealProposalStatus::Accepted)
        ->and($rejected->refresh()->status)->toBe(MealProposalStatus::Rejected)
        ->and($planned->refresh()->meal_slot_id)->toBe($second->id);
});

it('applies policies to every M2 team-owned mutation model', function () {
    $workspace = m21Workspace();
    $person = $workspace['team']->people()->sole();
    $preference = app(RecordPreference::class)->handle($workspace['team'], $workspace['user'], 'Olives', PreferenceSentiment::Dislike, PreferenceProvenance::Stated, $person);
    $constraint = app(RecordConstraint::class)->handle($workspace['team'], $workspace['user'], ConstraintKind::Dietary, 'No alcohol', directlyConfirmed: true, person: $person);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $proposal = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Dinner', $slot);
    $planned = app(AcceptMealProposal::class)->handle($proposal, $workspace['user']);
    $outsider = User::factory()->create();

    expect($workspace['user']->can('update', $preference))->toBeTrue()
        ->and($workspace['user']->can('delete', $constraint))->toBeTrue()
        ->and($workspace['user']->can('update', $proposal))->toBeTrue()
        ->and($workspace['user']->can('update', $planned))->toBeTrue()
        ->and($workspace['user']->can('view', $person))->toBeTrue()
        ->and($outsider->can('update', $preference))->toBeFalse()
        ->and($outsider->can('delete', $constraint))->toBeFalse()
        ->and($outsider->can('update', $proposal))->toBeFalse()
        ->and($outsider->can('update', $planned))->toBeFalse()
        ->and($outsider->can('view', $person))->toBeFalse();
});

it('rejects safety evidence from a different author role or family', function (string $case) {
    $workspace = m21Workspace();
    $message = m21UserMessage($workspace);

    if ($case === 'assistant') {
        $message->update(['role' => MessageRole::Assistant]);
    }

    if ($case === 'foreign-author') {
        $message->update(['user_id' => User::factory()->create()->id]);
    }

    if ($case === 'foreign-team') {
        $other = User::factory()->create();
        $message->update(['team_id' => app(CreateTeamForUser::class)->handle($other, 'Other')->id]);
    }

    app(RecordConstraint::class)->handle(
        team: $workspace['team'],
        user: $workspace['user'],
        kind: ConstraintKind::Allergy,
        subject: 'Peanuts',
        confirmationMessage: $message->fresh(),
    );
})->with(['assistant', 'foreign-author', 'foreign-team'])->throws(AuthorizationException::class);

it('makes meal proposal decisions a one-way state transition', function () {
    $workspace = m21Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);

    $accepted = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Satay chicken', $slot);
    app(AcceptMealProposal::class)->handle($accepted, $workspace['user']);
    expect(fn () => app(RejectMealProposal::class)->handle($accepted, $workspace['user']))->toThrow(ValidationException::class);

    $rejected = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Pork katsu', $slot);
    app(RejectMealProposal::class)->handle($rejected, $workspace['user']);
    expect(fn () => app(AcceptMealProposal::class)->handle($rejected, $workspace['user']))->toThrow(ValidationException::class)
        ->and($accepted->refresh()->status)->toBe(MealProposalStatus::Accepted)
        ->and($rejected->refresh()->status)->toBe(MealProposalStatus::Rejected);
});

it('replays an already completed client turn without a second model invocation', function () {
    $workspace = m21Workspace();
    $engine = Mockery::mock(ChefConversationEngine::class);
    $engine->shouldReceive('streamResponse')->once()->andReturn([
        new AssistantStreamChunk('delta', 'One response.'),
        new AssistantStreamChunk('complete'),
    ]);
    app()->instance(ChefConversationEngine::class, $engine);
    $clientId = (string) Str::uuid();

    $first = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Plan dinner.', 'client_message_id' => $clientId,
    ]);
    expect($first->streamedContent())->toContain('One response.');
    $workspace['conversation']->messages()->where('client_message_id', $clientId)->update([
        'response_status' => MessageResponseStatus::Processing,
        'response_completed_at' => null,
    ]);

    $second = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Plan dinner.', 'client_message_id' => $clientId,
    ]);

    expect($second->streamedContent())->toContain('One response.')
        ->and($workspace['conversation']->messages()->where('role', MessageRole::User)->count())->toBe(1)
        ->and($workspace['conversation']->messages()->where('role', MessageRole::Assistant)->whereNotNull('in_reply_to_message_id')->count())->toBe(1)
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->response_status)->toBe(MessageResponseStatus::Completed);
});

it('rejects a reused client id with changed content and an actively processing turn', function () {
    $workspace = m21Workspace();
    $clientId = (string) Str::uuid();
    $message = app(CreateUserMessage::class)->handle($workspace['conversation'], $workspace['user'], 'Original', $clientId);

    $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Changed', 'client_message_id' => $clientId,
    ])->assertSessionHasErrors('client_message_id');

    $message->update(['response_status' => MessageResponseStatus::Processing, 'response_started_at' => now()]);
    $this->postJson(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Original', 'client_message_id' => $clientId,
    ])->assertConflict();
});

it('marks a failed turn retryable and persists only one response', function () {
    $workspace = m21Workspace();
    $failing = Mockery::mock(ChefConversationEngine::class);
    $failing->shouldReceive('streamResponse')->once()->andReturnUsing(function (): iterable {
        throw new RuntimeException('Provider failed.');
        yield;
    });
    app()->instance(ChefConversationEngine::class, $failing);
    $clientId = (string) Str::uuid();

    $failed = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Try this safely.', 'client_message_id' => $clientId,
    ]);
    expect($failed->streamedContent())->toContain('"type":"error"')
        ->toContain('"code":"unknown"')
        ->toContain('"retryable":true');

    $successful = Mockery::mock(ChefConversationEngine::class);
    $successful->shouldReceive('streamResponse')->once()->andReturn([new AssistantStreamChunk('delta', 'Recovered.'), new AssistantStreamChunk('complete')]);
    app()->instance(ChefConversationEngine::class, $successful);
    $retry = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Try this safely.', 'client_message_id' => $clientId,
    ]);

    expect($retry->streamedContent())->toContain('Recovered.')
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->metadata['response']['attempts'])->toBe(2)
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->response()->count())->toBe(1);
});

it('keeps every M2 mutation route behind the active family boundary', function (string $routeName, string $method, string $modelKey, array $payload) {
    $workspace = m21Workspace();
    $person = $workspace['team']->people()->sole();
    $preference = app(RecordPreference::class)->handle($workspace['team'], $workspace['user'], 'Olives', PreferenceSentiment::Dislike, PreferenceProvenance::Stated, $person);
    $constraint = app(RecordConstraint::class)->handle($workspace['team'], $workspace['user'], ConstraintKind::Dietary, 'No alcohol', directlyConfirmed: true, person: $person);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $target = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Dinner, $workspace['team']->people);
    $proposal = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Dinner', $slot);
    $planned = app(AcceptMealProposal::class)->handle($proposal, $workspace['user']);
    $models = compact('preference', 'constraint', 'proposal', 'planned', 'target');

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    $response = $this->actingAs($outsider)->call(strtoupper($method), route($routeName, $models[$modelKey]), $payload);

    $response->assertNotFound();
})->with([
    ['preferences.update', 'put', 'preference', ['subject' => 'Olives', 'sentiment' => 'dislike', 'strength' => 4]],
    ['preferences.destroy', 'delete', 'preference', []],
    ['constraints.update', 'put', 'constraint', ['subject' => 'No alcohol', 'explicitly_confirmed' => true]],
    ['constraints.destroy', 'delete', 'constraint', []],
    ['meal-proposals.accept', 'put', 'proposal', []],
    ['meal-proposals.reject', 'put', 'proposal', []],
    ['planned-meals.move', 'put', 'planned', ['meal_slot_id' => 1]],
]);
