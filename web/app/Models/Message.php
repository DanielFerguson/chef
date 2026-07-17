<?php

namespace App\Models;

use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $team_id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property MessageRole $role
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property string|null $client_message_id
 */
#[Fillable(['team_id', 'conversation_id', 'user_id', 'in_reply_to_message_id', 'role', 'content', 'content_redacted_at', 'metadata', 'client_message_id', 'response_status', 'response_error', 'response_started_at', 'response_completed_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_message_id');
    }

    /** @return HasOne<Message, $this> */
    public function response(): HasOne
    {
        return $this->hasOne(self::class, 'in_reply_to_message_id');
    }

    /** @return HasMany<ConversationFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(ConversationFeedback::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'metadata' => 'array',
            'content_redacted_at' => 'datetime',
            'response_status' => MessageResponseStatus::class,
            'response_started_at' => 'datetime',
            'response_completed_at' => 'datetime',
        ];
    }
}
