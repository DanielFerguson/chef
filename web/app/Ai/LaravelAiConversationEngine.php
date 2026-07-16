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
    public function __construct(private readonly BuildConversationRecoveryReply $buildRecoveryReply) {}

    public function respondTo(Conversation $conversation, Message $message): AssistantReply
    {
        $actor = $message->author()->firstOrFail();
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $initialShoppingRevision = $conversation->mealPlan?->shoppingList?->revision;
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->prompt($message->content);
        $content = trim($response->text) === ''
            ? $this->buildRecoveryReply->handle($conversation, $message, $initialPlanRevision, $initialShoppingRevision)
            : $response->text;

        return new AssistantReply(
            content: $content,
            metadata: ['invocation_id' => $response->invocationId],
        );
    }

    /** @return iterable<AssistantStreamChunk> */
    public function streamResponse(Conversation $conversation, Message $message): iterable
    {
        $actor = $message->author()->firstOrFail();
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $initialShoppingRevision = $conversation->mealPlan?->shoppingList?->revision;
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->stream($message->content);
        $content = '';

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                $content .= $event->delta;
                yield new AssistantStreamChunk(type: 'delta', delta: $event->delta);
            }
        }

        if (trim($content) === '') {
            yield new AssistantStreamChunk(
                type: 'delta',
                delta: $this->buildRecoveryReply->handle($conversation, $message, $initialPlanRevision, $initialShoppingRevision),
            );
        }

        yield new AssistantStreamChunk(
            type: 'complete',
            metadata: ['invocation_id' => $response->invocationId],
        );
    }
}
