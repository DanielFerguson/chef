<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\CreateUserMessage;
use App\Ai\Contracts\ChefConversationEngine;
use App\Enums\MessageRole;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConversationMessageStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Conversation $conversation,
        CreateUserMessage $createUserMessage,
        ChefConversationEngine $engine,
    ): StreamedResponse {
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

        return response()->stream(function () use ($conversation, $message, $engine): void {
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

                $assistant = $conversation->messages()->create([
                    'team_id' => $conversation->team_id,
                    'role' => MessageRole::Assistant,
                    'content' => $content,
                    'metadata' => $metadata,
                ]);

                echo json_encode(['type' => 'persisted', 'message_id' => $assistant->id], JSON_THROW_ON_ERROR)."\n";
            } catch (Throwable $exception) {
                report($exception);
                echo json_encode(['type' => 'error', 'message' => 'Chef could not finish that response. Please try again.'], JSON_THROW_ON_ERROR)."\n";
            }
        }, headers: [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
