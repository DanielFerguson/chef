<?php

namespace App\Jobs;

use App\Models\PendingMessageAttachmentDeletion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeletePendingMessageAttachmentFilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'pending-message-attachment-deletions';
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $failedDeletionIds = [];

        PendingMessageAttachmentDeletion::query()
            ->oldest('id')
            ->limit(100)
            ->get()
            ->each(function (PendingMessageAttachmentDeletion $deletion) use (&$failedDeletionIds): void {
                try {
                    if (! Storage::disk($deletion->disk)->delete($deletion->path)) {
                        $failedDeletionIds[] = $deletion->id;

                        return;
                    }

                    $deletion->delete();
                } catch (Throwable $exception) {
                    report($exception);
                    $failedDeletionIds[] = $deletion->id;
                }
            });

        if ($failedDeletionIds !== []) {
            throw new RuntimeException('Private attachment cleanup remains pending.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Private attachment cleanup will be retried by the scheduler.', [
            'pending_deletion_count' => PendingMessageAttachmentDeletion::query()->count(),
        ]);
    }
}
