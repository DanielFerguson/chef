<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use Laravel\Ai\Models\Conversation as AiConversation;

class DeleteAiConversationLedger
{
    public function handle(Conversation $conversation): void
    {
        if ($conversation->ai_conversation_id === null) {
            return;
        }

        $this->handleId($conversation->ai_conversation_id);
    }

    public function handleId(string $aiConversationId): void
    {
        $conversation = AiConversation::query()->find($aiConversationId);

        if ($conversation === null) {
            return;
        }

        $conversation->messages()->delete();
        $conversation->delete();
    }
}
