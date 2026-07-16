<?php

namespace App\Actions\Conversations;

use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class CreateUserMessage
{
    public function handle(Conversation $conversation, User $user, string $content, string $clientMessageId): Message
    {
        if (! $user->can('update', $conversation)) {
            throw new AuthorizationException('You cannot contribute to this conversation.');
        }

        $message = Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'client_message_id' => $clientMessageId],
            [
                'team_id' => $conversation->team_id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'content' => $content,
                'response_status' => MessageResponseStatus::Pending,
            ],
        );

        if ($message->user_id !== $user->id || $message->content !== $content) {
            throw ValidationException::withMessages([
                'client_message_id' => 'That client message identifier was already used for different content.',
            ]);
        }

        return $message;
    }
}
