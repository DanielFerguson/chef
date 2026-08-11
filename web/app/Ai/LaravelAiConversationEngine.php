<?php

namespace App\Ai;

use App\Actions\Conversations\LinkAiConversation;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantReply;
use App\Ai\Data\AssistantStreamChunk;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use RuntimeException;
use Throwable;

class LaravelAiConversationEngine implements ChefConversationEngine
{
    public function __construct(
        private readonly BuildConversationRecoveryReply $buildRecoveryReply,
        private readonly AssessMealPlanReadiness $assessReadiness,
        private readonly LinkAiConversation $linkAiConversation,
    ) {}

    public function respondTo(Conversation $conversation, Message $message): AssistantReply
    {
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $response = $this->agentFor($conversation, $message)->prompt(
            $this->promptFor($conversation, $message),
            attachments: $this->attachmentsFor($message),
        );
        $this->linkResponse($conversation, $message, $response);
        $pendingApproval = $response->hasPendingApprovals()
            ? $this->projectPendingApproval($response->pendingApprovals)
            : null;
        $content = trim($response->text) === ''
            ? $this->fallbackContent($conversation, $message, $initialPlanRevision, $pendingApproval)
            : $response->text;

        return new AssistantReply(
            content: $content,
            metadata: array_filter([
                'invocation_id' => $response->invocationId,
                'ai_conversation_id' => $response->conversationId,
                'pending_tool_approval' => $pendingApproval,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /** @return iterable<AssistantStreamChunk> */
    public function streamResponse(Conversation $conversation, Message $message): iterable
    {
        $initialPlanRevision = $conversation->mealPlan?->revision;
        $response = $this->agentFor($conversation, $message)->stream(
            $this->promptFor($conversation, $message),
            attachments: $this->attachmentsFor($message),
        );
        $content = '';
        $recoveredFromFailure = false;
        /** @var array{id: string, tool: string, plan_revision: int, reason: string|null}|null $pendingApproval */
        $pendingApproval = null;

        try {
            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    $content .= $event->delta;
                    yield new AssistantStreamChunk(type: 'delta', delta: $event->delta);
                }

                if ($event instanceof ToolApprovalRequest) {
                    $pendingApproval = $this->projectPendingApproval($event->pendingApprovals);

                    if (trim($content) === '') {
                        $delta = $this->approvalRequestCopy();
                        $content .= $delta;
                        yield new AssistantStreamChunk(type: 'delta', delta: $delta);
                    }

                    yield new AssistantStreamChunk(
                        type: 'tool_approval_request',
                        metadata: ['pending_tool_approval' => $pendingApproval],
                    );
                }
            }

            $this->linkResponse($conversation, $message, $response);
        } catch (Throwable $exception) {
            try {
                $recovery = $this->fallbackContent(
                    $conversation,
                    $message,
                    $initialPlanRevision,
                    $pendingApproval,
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

        if (trim($content) === '') {
            $delta = $this->fallbackContent($conversation, $message, $initialPlanRevision, $pendingApproval);
            $content .= $delta;
            yield new AssistantStreamChunk(type: 'delta', delta: $delta);
        }

        yield new AssistantStreamChunk(
            type: 'complete',
            metadata: array_filter([
                'invocation_id' => $response->invocationId,
                'ai_conversation_id' => $response->conversationId ?? $conversation->ai_conversation_id,
                'recovered_from_failure' => $recoveredFromFailure,
                'pending_tool_approval' => $pendingApproval,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function agentFor(Conversation $conversation, Message $message): ChefAgent
    {
        $actor = $message->author()->firstOrFail();
        $agent = new ChefAgent($conversation, $message->id, $actor, $message);
        $aiConversationId = $this->linkAiConversation->ensure($conversation, $message);

        return $agent->continue($aiConversationId, as: $conversation);
    }

    private function promptFor(Conversation $conversation, Message $message): string|Decisions
    {
        $approval = $message->metadata['tool_approval'] ?? null;

        if (! is_array($approval)) {
            return trim($message->content) === ''
                ? 'The user shared these photos for meal-planning context.'
                : $message->content;
        }

        if ($conversation->ai_conversation_id === null || ! is_string($approval['id'] ?? null)) {
            throw ValidationException::withMessages([
                'approval' => 'That plan approval cannot be resumed. Review the latest plan and try again.',
            ]);
        }

        $decision = ($approval['decision'] ?? null) === 'approve'
            ? Decision::approve()
            : Decision::reject('The household chose to keep editing this plan. Do not approve it.');

        return Decisions::from([$approval['id'] => $decision]);
    }

    /** @return list<StoredImage> */
    private function attachmentsFor(Message $message): array
    {
        $images = [];

        foreach ($message->attachments()->get() as $attachment) {
            $images[] = Image::fromStorage($attachment->path, $attachment->disk)
                ->withMimeType($attachment->mime_type);
        }

        return $images;
    }

    /**
     * @param  Collection<int, PendingApproval>  $approvals
     * @return array{id: string, tool: string, plan_revision: int, reason: string|null}
     */
    private function projectPendingApproval(Collection $approvals): array
    {
        if ($approvals->count() !== 1) {
            throw new RuntimeException('Chef expected exactly one pending plan approval.');
        }

        $approval = $approvals->sole();
        $planRevision = $approval->arguments['plan_revision'] ?? null;

        if ($approval->tool !== 'ConfirmPlan' || ! is_numeric($planRevision)) {
            throw new RuntimeException('Chef received an unexpected pending tool approval.');
        }

        return [
            'id' => $approval->id,
            'tool' => 'ConfirmPlan',
            'plan_revision' => (int) $planRevision,
            'reason' => $approval->reason,
        ];
    }

    private function linkResponse(
        Conversation $conversation,
        Message $message,
        AgentResponse|StreamableAgentResponse $response,
    ): void {
        if ($response->conversationId !== null) {
            $this->linkAiConversation->handle($conversation, $message, $response->conversationId);
        }
    }

    /** @param array{id: string, tool: string, plan_revision: int, reason: string|null}|null $pendingApproval */
    private function fallbackContent(
        Conversation $conversation,
        Message $message,
        ?int $initialPlanRevision,
        ?array $pendingApproval,
    ): string {
        if ($pendingApproval !== null) {
            return $this->approvalRequestCopy();
        }

        $decision = $message->metadata['tool_approval']['decision'] ?? null;

        if ($decision === 'reject') {
            return 'No problem — I have not approved the plan. You can keep chatting or edit any meal before reviewing it again.';
        }

        if ($decision === 'approve') {
            $mealPlan = $conversation->mealPlan?->refresh();

            if ($mealPlan !== null) {
                $readiness = $this->assessReadiness->handle($mealPlan);

                if ($readiness['confirmed'] && ! $readiness['ready_for_approval']) {
                    return 'Approved — recipe preparation has started. Checkout remains with your household.';
                }
            }
        }

        return $this->buildRecoveryReply->handle($conversation, $message, $initialPlanRevision);
    }

    private function approvalRequestCopy(): string
    {
        return 'Your plan is ready for review. Use the approval card to approve it, or keep editing before anything starts.';
    }
}
