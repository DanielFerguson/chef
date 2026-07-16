<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\RecordConversationFeedback;
use App\Enums\ConversationFeedbackContext;
use App\Enums\ConversationFeedbackRating;
use App\Enums\ConversationFeedbackReason;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationFeedbackController extends Controller
{
    public function update(Request $request, Conversation $conversation, RecordConversationFeedback $recordFeedback): RedirectResponse
    {
        $this->authorize('update', $conversation);
        $validated = $request->validate([
            'context' => ['required', Rule::in([ConversationFeedbackContext::PlanningConfirmed->value])],
            'rating' => ['required', Rule::enum(ConversationFeedbackRating::class)],
            'reasons' => ['sometimes', 'array', 'max:7'],
            'reasons.*' => [Rule::enum(ConversationFeedbackReason::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $recordFeedback->forPlanningConfirmation(
            $conversation,
            $request->user(),
            ConversationFeedbackRating::from($validated['rating']),
            $validated['reasons'] ?? [],
            $validated['comment'] ?? null,
        );

        return back();
    }

    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);
        $feedback = $conversation->feedback()
            ->whereBelongsTo($request->user())
            ->where('context', ConversationFeedbackContext::PlanningConfirmed)
            ->firstOrFail();
        $this->authorize('delete', $feedback);
        $feedback->delete();

        return back();
    }
}
