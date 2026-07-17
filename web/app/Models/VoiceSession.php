<?php

namespace App\Models;

use App\Enums\VoiceSessionStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $conversation_id
 * @property int $user_id
 * @property VoiceSessionStatus $status
 * @property string $model
 * @property string $voice
 * @property CarbonImmutable $microphone_permission_granted_at
 * @property CarbonImmutable|null $microphone_permission_revoked_at
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['public_id', 'team_id', 'conversation_id', 'user_id', 'status', 'model', 'voice', 'last_error', 'microphone_permission_granted_at', 'microphone_permission_revoked_at', 'connected_at', 'ended_at', 'expires_at'])]
class VoiceSession extends Model
{
    use ResolvesWithinCurrentTeam;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<VoiceToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(VoiceToolCall::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VoiceSessionStatus::class,
            'microphone_permission_granted_at' => 'datetime',
            'microphone_permission_revoked_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
