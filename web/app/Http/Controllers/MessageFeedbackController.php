<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\RecordConversationFeedback;
use App\Enums\ConversationFeedbackRating;
use App\Enums\ConversationFeedbackReason;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageFeedbackController extends Controller
{
    public function update(Request $request, Message $message, RecordConversationFeedback $recordFeedback): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', Rule::enum(ConversationFeedbackRating::class)],
            'reasons' => ['sometimes', 'array', 'max:7'],
            'reasons.*' => [Rule::enum(ConversationFeedbackReason::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $recordFeedback->forMessage(
            $message,
            $request->user(),
            ConversationFeedbackRating::from($validated['rating']),
            $validated['reasons'] ?? [],
            $validated['comment'] ?? null,
        );

        return back();
    }

    public function destroy(Request $request, Message $message): RedirectResponse
    {
        $feedback = $message->feedback()->whereBelongsTo($request->user())->firstOrFail();
        $this->authorize('delete', $feedback);
        $feedback->delete();

        return back();
    }
}
