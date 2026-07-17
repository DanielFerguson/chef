<?php

namespace App\Models;

use App\Enums\VoiceToolCallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $voice_session_id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $provider_call_id
 * @property string $tool_name
 * @property array<string, mixed> $arguments
 * @property string $client_message_id
 * @property VoiceToolCallStatus $status
 * @property array<string, mixed>|null $result
 */
#[Fillable(['team_id', 'voice_session_id', 'conversation_id', 'user_id', 'provider_call_id', 'tool_name', 'arguments', 'client_message_id', 'user_message_id', 'assistant_message_id', 'status', 'result', 'last_error', 'started_at', 'completed_at'])]
class VoiceToolCall extends Model
{
    /** @return BelongsTo<VoiceSession, $this> */
    public function voiceSession(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'user_message_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'assistant_message_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'status' => VoiceToolCallStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
