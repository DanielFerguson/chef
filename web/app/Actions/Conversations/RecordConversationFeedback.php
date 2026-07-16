<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationFeedbackContext;
use App\Enums\ConversationFeedbackRating;
use App\Models\Conversation;
use App\Models\ConversationFeedback;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordConversationFeedback
{
    /** @param array<int, string> $reasons */
    public function forMessage(Message $message, User $user, ConversationFeedbackRating $rating, array $reasons = [], ?string $comment = null): ConversationFeedback
    {
        if ($message->role->value !== 'assistant') {
            throw ValidationException::withMessages(['message' => 'Feedback can only be attached to a Chef response.']);
        }

        $conversation = $message->conversation;

        return $this->record($conversation, $user, ConversationFeedbackContext::AssistantMessage, $rating, $reasons, $comment, $message);
    }

    /** @param array<int, string> $reasons */
    public function forPlanningConfirmation(Conversation $conversation, User $user, ConversationFeedbackRating $rating, array $reasons = [], ?string $comment = null): ConversationFeedback
    {
        if ($conversation->mealPlan?->planning_confirmed_at === null) {
            throw ValidationException::withMessages(['context' => 'The plan must be confirmed before collecting this checkpoint feedback.']);
        }

        return $this->record($conversation, $user, ConversationFeedbackContext::PlanningConfirmed, $rating, $reasons, $comment);
    }

    /** @param array<int, string> $reasons */
    private function record(Conversation $conversation, User $user, ConversationFeedbackContext $context, ConversationFeedbackRating $rating, array $reasons, ?string $comment, ?Message $message = null): ConversationFeedback
    {
        if (! $user->memberships()->where('team_id', $conversation->team_id)->exists()) {
            throw new AuthorizationException('You cannot leave feedback on this conversation.');
        }

        if ($message !== null && ($message->team_id !== $conversation->team_id || $message->conversation_id !== $conversation->id)) {
            throw new AuthorizationException('That response does not belong to this conversation.');
        }

        $mealPlan = $conversation->mealPlan;
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);
        $invocationId = $message?->metadata['invocation_id'] ?? null;

        return DB::transaction(function () use ($conversation, $user, $context, $rating, $reasons, $comment, $message, $mealPlan, $invocationId): ConversationFeedback {
            $identity = $message === null
                ? ['conversation_id' => $conversation->id, 'meal_plan_id' => $mealPlan?->id, 'user_id' => $user->id, 'context' => $context]
                : ['message_id' => $message->id, 'user_id' => $user->id];

            return ConversationFeedback::query()->updateOrCreate($identity, [
                'team_id' => $conversation->team_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
                'meal_plan_id' => $mealPlan?->id,
                'context' => $context,
                'rating' => $rating,
                'reasons' => array_values(array_unique($reasons)),
                'comment' => $comment,
                'plan_revision' => $mealPlan?->revision,
                'milestone' => $mealPlan?->milestones()->latest('achieved_at')->value('kind'),
                'invocation_id' => is_string($invocationId) ? $invocationId : null,
            ]);
        });
    }
}
