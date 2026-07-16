<?php

namespace App\Actions\Conversations;

use App\Ai\Contracts\ChefConversationEngine;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class SendMessageToChef
{
    public function __construct(private readonly ChefConversationEngine $engine) {}

    public function handle(
        Conversation $conversation,
        User $user,
        string $content,
        ?string $clientMessageId = null,
    ): Message {
        if (! $user->memberships()->where('team_id', $conversation->team_id)->exists()) {
            throw new AuthorizationException('You do not belong to this family.');
        }

        $userMessage = $conversation->messages()->create([
            'team_id' => $conversation->team_id,
            'user_id' => $user->id,
            'role' => MessageRole::User,
            'content' => $content,
            'client_message_id' => $clientMessageId,
        ]);

        $reply = $this->engine->respondTo($conversation, $userMessage);

        return $conversation->messages()->create([
            'team_id' => $conversation->team_id,
            'role' => MessageRole::Assistant,
            'content' => $reply->content,
            'metadata' => $reply->metadata,
        ]);
    }
}
