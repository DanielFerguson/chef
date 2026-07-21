<?php

namespace App\Actions\Conversations;

use App\Models\Message;

class RecordMessageResponseAttempt
{
    public function handle(Message $message): int
    {
        $message->refresh();
        $metadata = $message->metadata ?? [];
        $response = is_array($metadata['response'] ?? null) ? $metadata['response'] : [];
        $attempts = max(0, (int) ($response['attempts'] ?? 0)) + 1;

        $message->update([
            'metadata' => [
                ...$metadata,
                'response' => [
                    ...$response,
                    'attempts' => $attempts,
                ],
            ],
        ]);

        return $attempts;
    }
}
