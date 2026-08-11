<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation as AiConversation;
use RuntimeException;

class LinkAiConversation
{
    public function __construct(
        private readonly DeleteAiConversationLedger $deleteLedger,
        private readonly ConversationStore $conversationStore,
    ) {}

    public function ensure(Conversation $conversation, Message $message): string
    {
        $aiConversationId = DB::transaction(function () use ($conversation, $message): string {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($locked->ai_conversation_id !== null) {
                return $locked->ai_conversation_id;
            }

            $aiConversationId = $this->conversationStore->storeConversation(
                AiConversation::participantType($locked),
                AiConversation::participantKey($locked),
                Str::limit($message->content === '' ? 'Photo planning turn' : $message->content, 100),
            );
            $cutoffMessageId = $locked->messages()
                ->where('id', '<', $message->id)
                ->max('id');

            $locked->update([
                'ai_conversation_id' => $aiConversationId,
                'ai_context_cutoff_message_id' => $cutoffMessageId,
            ]);

            return $aiConversationId;
        });

        $conversation->refresh();

        return $aiConversationId;
    }

    public function handle(Conversation $conversation, Message $message, string $aiConversationId): void
    {
        $linked = DB::transaction(function () use ($conversation, $message, $aiConversationId): bool {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($locked->ai_conversation_id !== null) {
                return hash_equals($locked->ai_conversation_id, $aiConversationId);
            }

            $cutoffMessageId = $locked->messages()
                ->where('id', '<', $message->id)
                ->max('id');

            $locked->update([
                'ai_conversation_id' => $aiConversationId,
                'ai_context_cutoff_message_id' => $cutoffMessageId,
            ]);

            return true;
        });

        if (! $linked) {
            $this->deleteLedger->handleId($aiConversationId);

            throw new RuntimeException('Chef created more than one AI conversation ledger for the same product conversation.');
        }

        $conversation->refresh();
    }
}
