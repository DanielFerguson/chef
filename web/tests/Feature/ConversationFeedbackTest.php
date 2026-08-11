<?php

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\ConversationFeedbackContext;
use App\Enums\ConversationFeedbackRating;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\TeamRole;
use App\Models\Conversation;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/** @return array{user: User, team: Team, plan: MealPlan, conversation: Conversation, message: Message} */
function feedbackWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Feedback family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->firstOrFail();
    $message = $conversation->messages()->where('role', 'assistant')->firstOrFail();
    $message->update(['metadata' => ['invocation_id' => 'invocation-123']]);

    return compact('user', 'team', 'plan', 'conversation', 'message');
}

it('records updates and withdraws attributed assistant message feedback', function () {
    $workspace = feedbackWorkspace();

    $this->actingAs($workspace['user'])->put(route('messages.feedback.update', $workspace['message']), [
        'rating' => 'unhelpful',
        'reasons' => ['wrong_action', 'incorrect_household_information'],
        'comment' => 'This was assigned to the wrong person.',
    ])->assertRedirect();

    $feedback = $workspace['message']->feedback()->sole();
    expect($feedback->team_id)->toBe($workspace['team']->id)
        ->and($feedback->conversation_id)->toBe($workspace['conversation']->id)
        ->and($feedback->meal_plan_id)->toBe($workspace['plan']->id)
        ->and($feedback->user_id)->toBe($workspace['user']->id)
        ->and($feedback->context)->toBe(ConversationFeedbackContext::AssistantMessage)
        ->and($feedback->rating)->toBe(ConversationFeedbackRating::Unhelpful)
        ->and($feedback->reasons)->toBe(['wrong_action', 'incorrect_household_information'])
        ->and($feedback->plan_revision)->toBe($workspace['plan']->refresh()->revision)
        ->and($feedback->invocation_id)->toBe('invocation-123');

    $this->put(route('messages.feedback.update', $workspace['message']), [
        'rating' => 'helpful',
        'reasons' => [],
        'comment' => null,
    ])->assertRedirect();

    expect($workspace['message']->feedback()->count())->toBe(1)
        ->and($feedback->refresh()->rating)->toBe(ConversationFeedbackRating::Helpful);

    $this->delete(route('messages.feedback.destroy', $workspace['message']))->assertRedirect();
    expect($workspace['message']->feedback()->count())->toBe(0);
});

it('keeps feedback private to its author while allowing family collaborators to respond independently', function () {
    $workspace = feedbackWorkspace();
    $collaborator = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $collaborator, TeamRole::Member);

    $this->actingAs($workspace['user'])->put(route('messages.feedback.update', $workspace['message']), [
        'rating' => 'helpful',
    ])->assertRedirect();
    $this->actingAs($collaborator)->put(route('messages.feedback.update', $workspace['message']), [
        'rating' => 'unhelpful',
        'reasons' => ['misunderstood'],
    ])->assertRedirect();

    expect($workspace['message']->feedback()->count())->toBe(2);

    $this->withoutVite()->get(route('meal-plans.show', $workspace['plan']))
        ->assertInertia(fn ($page) => $page
            ->has('workspace.conversation.messages.0.feedback', 1)
            ->where('workspace.conversation.messages.0.feedback.0.user_id', $collaborator->id));
});

it('rejects non-assistant and cross-family message feedback', function () {
    $workspace = feedbackWorkspace();
    $userMessage = $workspace['conversation']->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => 'user',
        'content' => 'Hello Chef.',
    ]);

    $this->actingAs($workspace['user'])->put(route('messages.feedback.update', $userMessage), [
        'rating' => 'helpful',
    ])->assertSessionHasErrors('message');

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    $this->actingAs($outsider)->put(route('messages.feedback.update', $workspace['message']), [
        'rating' => 'helpful',
    ])->assertForbidden();
});

it('collects one planning checkpoint response only after confirmation', function () {
    $workspace = feedbackWorkspace();
    $payload = ['context' => 'planning_confirmed', 'rating' => 'helpful'];

    $this->actingAs($workspace['user'])->put(route('conversations.feedback.update', $workspace['conversation']), $payload)
        ->assertSessionHasErrors('context');

    app(RecordMealPlanMilestone::class)->handle($workspace['plan'], $workspace['user'], MealPlanMilestoneKind::PlanningConfirmed);

    $this->put(route('conversations.feedback.update', $workspace['conversation']), $payload)->assertRedirect();
    $this->put(route('conversations.feedback.update', $workspace['conversation']), [
        ...$payload,
        'rating' => 'unhelpful',
        'reasons' => ['no_forward_momentum'],
    ])->assertRedirect();

    $feedback = $workspace['conversation']->feedback()->sole();
    expect($feedback->context)->toBe(ConversationFeedbackContext::PlanningConfirmed)
        ->and($feedback->rating)->toBe(ConversationFeedbackRating::Unhelpful)
        ->and($feedback->milestone)->toBe(MealPlanMilestoneKind::PlanningConfirmed->value);
});
