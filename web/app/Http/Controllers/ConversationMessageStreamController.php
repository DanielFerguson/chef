<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Conversations\RecordMessageResponseAttempt;
use App\Actions\Conversations\RecordMessageResponseFailure;
use App\Ai\Contracts\ChefConversationEngine;
use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConversationMessageStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Conversation $conversation,
        CreateUserMessage $createUserMessage,
        RecordMessageResponseAttempt $recordResponseAttempt,
        RecordMessageResponseFailure $recordResponseFailure,
        ChefConversationEngine $engine,
    ): StreamedResponse|JsonResponse {
        $this->authorize('update', $conversation);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
            'client_message_id' => ['required', 'uuid'],
        ]);
        $message = $createUserMessage->handle(
            $conversation,
            $request->user(),
            $validated['content'],
            $validated['client_message_id'],
        );

        $existingResponse = $message->response()->first();

        if ($existingResponse !== null) {
            $message->update([
                'response_status' => MessageResponseStatus::Completed,
                'response_error' => null,
                'response_completed_at' => $message->response_completed_at ?? now(),
            ]);

            return $this->replay($existingResponse);
        }

        $claimed = Message::query()
            ->whereKey($message->id)
            ->where(function ($query): void {
                $query->whereNull('response_status')
                    ->orWhereIn('response_status', [MessageResponseStatus::Pending->value, MessageResponseStatus::Failed->value])
                    ->orWhere(function ($stale): void {
                        $stale->where('response_status', MessageResponseStatus::Processing->value)
                            ->where('response_started_at', '<', now()->subMinutes(10));
                    });
            })
            ->update([
                'response_status' => MessageResponseStatus::Processing,
                'response_error' => null,
                'response_started_at' => now(),
            ]);

        if ($claimed !== 1) {
            return response()->json([
                'message' => 'Chef is already responding to that message.',
            ], 409);
        }

        $attempt = $recordResponseAttempt->handle($message);

        return response()->stream(function () use ($conversation, $message, $engine, $recordResponseFailure, $attempt): void {
            $content = '';
            $metadata = [];

            try {
                foreach ($engine->streamResponse($conversation, $message) as $chunk) {
                    $content .= $chunk->delta;
                    $metadata = [...$metadata, ...$chunk->metadata];
                    echo json_encode([
                        'type' => $chunk->type,
                        'delta' => $chunk->delta,
                        'metadata' => $chunk->metadata,
                    ], JSON_THROW_ON_ERROR)."\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                if (trim($content) === '') {
                    throw new RuntimeException('Chef completed without a visible response.');
                }

                $assistant = $conversation->messages()->firstOrCreate([
                    'in_reply_to_message_id' => $message->id,
                ], [
                    'team_id' => $conversation->team_id,
                    'role' => MessageRole::Assistant,
                    'content' => $content,
                    'metadata' => $metadata,
                ]);

                $message->update([
                    'response_status' => MessageResponseStatus::Completed,
                    'response_completed_at' => now(),
                ]);

                echo json_encode(['type' => 'persisted', 'message_id' => $assistant->id], JSON_THROW_ON_ERROR)."\n";
            } catch (Throwable $exception) {
                $failure = $recordResponseFailure->handle($message, $exception, $attempt);
                echo json_encode([
                    'type' => 'error',
                    'code' => $failure->code->value,
                    'message' => $failure->message,
                    'retryable' => $failure->retryable,
                ], JSON_THROW_ON_ERROR)."\n";
            }
        }, headers: [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function replay(Message $assistant): StreamedResponse
    {
        return response()->stream(function () use ($assistant): void {
            echo json_encode(['type' => 'delta', 'delta' => $assistant->content, 'metadata' => []], JSON_THROW_ON_ERROR)."\n";
            echo json_encode(['type' => 'complete', 'delta' => '', 'metadata' => $assistant->metadata ?? []], JSON_THROW_ON_ERROR)."\n";
            echo json_encode(['type' => 'persisted', 'message_id' => $assistant->id], JSON_THROW_ON_ERROR)."\n";
        }, headers: [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
