<?php

namespace App\Actions\MealPlans;

use App\Actions\Conversations\DeleteAiConversationLedger;
use App\Jobs\DeletePendingMessageAttachmentFilesJob;
use App\Models\Conversation;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\PendingMessageAttachmentDeletion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteMealPlan
{
    public function __construct(private readonly DeleteAiConversationLedger $deleteAiConversationLedger) {}

    public function handle(MealPlan $mealPlan, User $user): void
    {
        if (! $user->can('delete', $mealPlan)) {
            throw new AuthorizationException('You cannot delete this meal plan.');
        }

        $hasAttachments = DB::transaction(function () use ($mealPlan): bool {
            $lockedMealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $conversations = $lockedMealPlan->conversations()->lockForUpdate()->get();
            $messages = Message::query()
                ->whereIn('conversation_id', $conversations->modelKeys())
                ->lockForUpdate()
                ->get();
            $attachments = MessageAttachment::query()
                ->whereIn('message_id', $messages->modelKeys())
                ->lockForUpdate()
                ->get();

            $attachments->each(fn (MessageAttachment $attachment) => PendingMessageAttachmentDeletion::query()->firstOrCreate([
                'disk' => $attachment->disk,
                'path' => $attachment->path,
            ]));

            // Conversations are nullable at the database boundary so they can exist
            // independently. A plan deletion is explicit, so remove its history too.
            $conversations->each(
                fn (Conversation $conversation) => $this->deleteAiConversationLedger->handle($conversation),
            );
            $lockedMealPlan->conversations()->delete();
            $lockedMealPlan->delete();

            return $attachments->isNotEmpty();
        });

        if ($hasAttachments) {
            try {
                DeletePendingMessageAttachmentFilesJob::dispatch();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
