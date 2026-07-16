<?php

namespace App\Actions\Conversations;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CreateUserMessage
{
    public function handle(Conversation $conversation, User $user, string $content, string $clientMessageId): Message
    {
        if (! $user->can('update', $conversation)) {
            throw new AuthorizationException('You cannot contribute to this conversation.');
        }

        return Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'client_message_id' => $clientMessageId],
            [
                'team_id' => $conversation->team_id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'content' => $content,
            ],
        );
    }
}
