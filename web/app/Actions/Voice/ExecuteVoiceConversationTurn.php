<?php

namespace App\Actions\Voice;

use App\Actions\Conversations\CreateUserMessage;
use App\Ai\Contracts\ChefConversationEngine;
use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Enums\VoiceSessionStatus;
use App\Enums\VoiceToolCallStatus;
use App\Models\Message;
use App\Models\User;
use App\Models\VoiceSession;
use App\Models\VoiceToolCall;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class ExecuteVoiceConversationTurn
{
    public const TOOL_NAME = 'continue_chef_conversation';

    public function __construct(
        private readonly CreateUserMessage $createUserMessage,
        private readonly ChefConversationEngine $engine,
    ) {}

    /** @param array{message: string} $arguments */
    public function handle(
        VoiceSession $session,
        User $user,
        string $providerCallId,
        string $toolName,
        array $arguments,
    ): VoiceToolCall {
        $this->authorizeSession($session, $user);

        if ($toolName !== self::TOOL_NAME) {
            throw ValidationException::withMessages(['tool_name' => 'That voice tool is not available.']);
        }

        $content = trim($arguments['message']);

        if ($content === '') {
            throw ValidationException::withMessages(['arguments.message' => 'The spoken message cannot be empty.']);
        }

        $call = VoiceToolCall::query()->firstOrCreate(
            [
                'voice_session_id' => $session->id,
                'provider_call_id' => $providerCallId,
            ],
            [
                'team_id' => $session->team_id,
                'conversation_id' => $session->conversation_id,
                'user_id' => $user->id,
                'tool_name' => $toolName,
                'arguments' => ['message' => $content],
                'client_message_id' => (string) Str::uuid(),
                'status' => VoiceToolCallStatus::Pending,
            ],
        );

        if ($call->user_id !== $user->id || $call->tool_name !== $toolName || $call->arguments !== ['message' => $content]) {
            throw ValidationException::withMessages([
                'provider_call_id' => 'That Realtime call identifier was already used for different input.',
            ]);
        }

        if ($call->status === VoiceToolCallStatus::Completed) {
            return $call;
        }

        $claimed = VoiceToolCall::query()
            ->whereKey($call->id)
            ->whereIn('status', [VoiceToolCallStatus::Pending->value, VoiceToolCallStatus::Failed->value])
            ->update([
                'status' => VoiceToolCallStatus::Processing,
                'last_error' => null,
                'started_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new ConflictHttpException('Chef is already handling that voice turn.');
        }

        $conversation = $session->conversation;
        $before = $this->artifactSnapshot($conversation->mealPlan);

        try {
            $userMessage = $this->createUserMessage->handle(
                $conversation,
                $user,
                $content,
                $call->client_message_id,
            );
            $userMessage->update([
                'metadata' => [
                    ...($userMessage->metadata ?? []),
                    'input_mode' => 'voice',
                    'voice_session_id' => $session->public_id,
                    'voice_tool_call_id' => $call->id,
                ],
                'response_status' => MessageResponseStatus::Processing,
                'response_started_at' => $userMessage->response_started_at ?? now(),
            ]);

            $assistant = $userMessage->response()->first();

            if ($assistant === null) {
                $reply = $this->engine->respondTo($conversation, $userMessage);

                if (trim($reply->content) === '') {
                    throw new RuntimeException('Chef completed without a visible voice response.');
                }

                $assistant = $conversation->messages()->create([
                    'team_id' => $conversation->team_id,
                    'role' => MessageRole::Assistant,
                    'in_reply_to_message_id' => $userMessage->id,
                    'content' => $reply->content,
                    'metadata' => [
                        ...$reply->metadata,
                        'input_mode' => 'voice',
                        'voice_session_id' => $session->public_id,
                        'voice_tool_call_id' => $call->id,
                    ],
                ]);
            }

            $userMessage->update([
                'response_status' => MessageResponseStatus::Completed,
                'response_error' => null,
                'response_completed_at' => now(),
            ]);

            $result = [
                'assistant_response' => $assistant->content,
                'user_message_id' => $userMessage->id,
                'assistant_message_id' => $assistant->id,
                'artifacts' => [
                    'before' => $before,
                    'after' => $this->artifactSnapshot($conversation->mealPlan?->refresh()),
                ],
            ];
            $call->update([
                'user_message_id' => $userMessage->id,
                'assistant_message_id' => $assistant->id,
                'status' => VoiceToolCallStatus::Completed,
                'result' => $result,
                'completed_at' => now(),
            ]);

            return $call->refresh();
        } catch (Throwable $exception) {
            if (isset($userMessage)) {
                $userMessage->update([
                    'response_status' => MessageResponseStatus::Failed,
                    'response_error' => 'Chef could not finish that voice response.',
                ]);
            }

            $call->update([
                'status' => VoiceToolCallStatus::Failed,
                'last_error' => 'Chef could not finish that voice response.',
            ]);

            throw $exception;
        }
    }

    private function authorizeSession(VoiceSession $session, User $user): void
    {
        if ($session->user_id !== $user->id || ! $user->can('update', $session->conversation)) {
            throw new AuthorizationException('You cannot use this voice session.');
        }

        if ($session->status !== VoiceSessionStatus::Active || $session->expires_at->isPast()) {
            throw ValidationException::withMessages(['voice_session' => 'This voice session is no longer active.']);
        }
    }

    /** @return array{meal_plan_id: int|null, plan_revision: int|null, shopping_list_id: int|null, shopping_revision: int|null} */
    private function artifactSnapshot(mixed $mealPlan): array
    {
        return [
            'meal_plan_id' => $mealPlan?->id,
            'plan_revision' => $mealPlan?->revision,
            'shopping_list_id' => $mealPlan?->shoppingList?->id,
            'shopping_revision' => $mealPlan?->shoppingList?->revision,
        ];
    }
}
