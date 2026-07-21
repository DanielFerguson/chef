<?php

namespace App\Actions\Conversations;

use App\Ai\ClassifyConversationFailure;
use App\Ai\Data\ConversationFailure;
use App\Enums\MessageResponseStatus;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordMessageResponseFailure
{
    public function __construct(private readonly ClassifyConversationFailure $classifyFailure) {}

    public function handle(Message $message, Throwable $exception, int $attempt): ConversationFailure
    {
        $failure = $this->classifyFailure->handle($exception);
        $failureId = (string) Str::uuid();
        $message->refresh();
        $metadata = $message->metadata ?? [];
        $response = is_array($metadata['response'] ?? null) ? $metadata['response'] : [];

        $message->update([
            'response_status' => MessageResponseStatus::Failed,
            'response_error' => $failure->message,
            'metadata' => [
                ...$metadata,
                'response' => [
                    ...$response,
                    'attempts' => $attempt,
                    'last_failure' => [
                        'id' => $failureId,
                        'code' => $failure->code->value,
                        'retryable' => $failure->retryable,
                        'occurred_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ]);

        try {
            Log::error('Chef conversation response failed.', [
                'failure_id' => $failureId,
                'failure_code' => $failure->code->value,
                'retryable' => $failure->retryable,
                'attempt' => $attempt,
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'exception_trace' => $exception->getTraceAsString(),
                'team_id' => $message->team_id,
                'conversation_id' => $message->conversation_id,
                'meal_plan_id' => $message->conversation->meal_plan_id,
                'message_id' => $message->id,
                'client_message_id' => $message->client_message_id,
            ]);
        } catch (Throwable) {
            // Failure persistence and the retry response must not depend on the logging backend.
        }

        return $failure;
    }
}
