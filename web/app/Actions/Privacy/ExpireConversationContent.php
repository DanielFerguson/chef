<?php

namespace App\Actions\Privacy;

use App\Models\Message;

class ExpireConversationContent
{
    public function handle(): int
    {
        $retentionDays = (int) config('chef.retention.conversations_days');
        $expired = 0;

        Message::query()
            ->whereNull('content_redacted_at')
            ->where('created_at', '<=', now()->subDays($retentionDays))
            ->select('id')
            ->chunkById(500, function ($messages) use (&$expired, $retentionDays): void {
                $ids = $messages->pluck('id');
                $expired += Message::query()->whereKey($ids)->update([
                    'content' => "Conversation content expired after {$retentionDays} days.",
                    'metadata' => null,
                    'response_error' => null,
                    'content_redacted_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        return $expired;
    }
}
