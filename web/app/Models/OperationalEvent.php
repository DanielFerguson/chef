<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $category
 * @property string $name
 * @property string $status
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $invocation_id
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $cache_read_tokens
 * @property int $reasoning_tokens
 * @property int $estimated_cost_microusd
 * @property int|null $duration_ms
 * @property array<string, scalar|null>|null $metadata
 * @property Carbon $occurred_at
 */
#[Fillable(['team_id', 'user_id', 'category', 'name', 'status', 'provider', 'model', 'invocation_id', 'prompt_tokens', 'completion_tokens', 'cache_read_tokens', 'reasoning_tokens', 'estimated_cost_microusd', 'duration_ms', 'subject_type', 'subject_id', 'metadata', 'occurred_at'])]
class OperationalEvent extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
