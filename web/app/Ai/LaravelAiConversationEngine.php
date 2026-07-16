<?php

namespace App\Ai;

use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantReply;
use App\Ai\Data\AssistantStreamChunk;
use App\Models\Conversation;
use App\Models\Message;
use Laravel\Ai\Streaming\Events\TextDelta;

class LaravelAiConversationEngine implements ChefConversationEngine
{
    public function respondTo(Conversation $conversation, Message $message): AssistantReply
    {
        $actor = $message->author()->firstOrFail();
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->prompt($message->content);

        return new AssistantReply(
            content: $response->text,
            metadata: ['invocation_id' => $response->invocationId],
        );
    }

    /** @return iterable<AssistantStreamChunk> */
    public function streamResponse(Conversation $conversation, Message $message): iterable
    {
        $actor = $message->author()->firstOrFail();
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->stream($message->content);

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                yield new AssistantStreamChunk(type: 'delta', delta: $event->delta);
            }
        }

        yield new AssistantStreamChunk(
            type: 'complete',
            metadata: ['invocation_id' => $response->invocationId],
        );
    }
}
