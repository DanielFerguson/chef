<?php

namespace App\Ai;

use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantReply;
use App\Ai\Data\AssistantStreamChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\OperationalMetrics;
use App\Support\UsageGuard;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

class LaravelAiConversationEngine implements ChefConversationEngine
{
    public function __construct(
        private readonly BuildConversationRecoveryReply $buildRecoveryReply,
        private readonly OperationalMetrics $metrics,
        private readonly UsageGuard $usageGuard,
    ) {}

    public function respondTo(Conversation $conversation, Message $message): AssistantReply
    {
        $actor = $message->author()->firstOrFail();
        $team = $conversation->team()->firstOrFail();
        $this->usageGuard->assertAiAllowed($team);
        $startedAt = hrtime(true);
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $initialShoppingRevision = $conversation->mealPlan?->shoppingList?->revision;
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->prompt($message->content);
        $content = trim($response->text) === ''
            ? $this->buildRecoveryReply->handle($conversation, $message, $initialPlanRevision, $initialShoppingRevision)
            : $response->text;

        $this->metrics->recordAi(
            $team,
            $actor,
            'conversation_response',
            'completed',
            $response->invocationId,
            $response->meta->provider,
            $response->meta->model,
            $response->usage,
            $this->elapsedMilliseconds($startedAt),
            'conversation',
            $conversation->id,
        );

        return new AssistantReply(
            content: $content,
            metadata: ['invocation_id' => $response->invocationId],
        );
    }

    /** @return iterable<AssistantStreamChunk> */
    public function streamResponse(Conversation $conversation, Message $message): iterable
    {
        $actor = $message->author()->firstOrFail();
        $team = $conversation->team()->firstOrFail();
        $startedAt = hrtime(true);
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $initialShoppingRevision = $conversation->mealPlan?->shoppingList?->revision;
        $response = (new ChefAgent($conversation, $message->id, $actor, $message))->stream($message->content);
        $content = '';
        $recoveredFromFailure = false;

        try {
            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    $content .= $event->delta;
                    yield new AssistantStreamChunk(type: 'delta', delta: $event->delta);
                }
            }
        } catch (Throwable $exception) {
            try {
                $recovery = $this->buildRecoveryReply->handle(
                    $conversation,
                    $message,
                    $initialPlanRevision,
                    $initialShoppingRevision,
                );
            } catch (Throwable) {
                throw $exception;
            }

            report($exception);
            $delta = (trim($content) === '' ? '' : "\n\n").$recovery;
            $content .= $delta;
            $recoveredFromFailure = true;
            yield new AssistantStreamChunk(type: 'delta', delta: $delta);
        }

        $this->metrics->recordAi(
            $team,
            $actor,
            'conversation_stream',
            $recoveredFromFailure ? 'recovered' : 'completed',
            $response->invocationId,
            null,
            null,
            $response->usage ?? null,
            $this->elapsedMilliseconds($startedAt),
            'conversation',
            $conversation->id,
            ['recovered' => $recoveredFromFailure],
        );

        if (trim($content) === '') {
            yield new AssistantStreamChunk(
                type: 'delta',
                delta: $this->buildRecoveryReply->handle($conversation, $message, $initialPlanRevision, $initialShoppingRevision),
            );
        }

        yield new AssistantStreamChunk(
            type: 'complete',
            metadata: [
                'invocation_id' => $response->invocationId,
                'recovered_from_failure' => $recoveredFromFailure,
            ],
        );
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
