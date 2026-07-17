<?php

namespace App\Models;

use App\Enums\BrowserConnectionStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int|null $user_id
 * @property string|null $name
 * @property BrowserConnectionStatus $status
 * @property string|null $pairing_code_hash
 * @property string|null $token_hash
 * @property string[] $allowed_origins
 * @property Carbon|null $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['uuid', 'team_id', 'user_id', 'name', 'status', 'pairing_code_hash', 'token_hash', 'allowed_origins', 'paired_at', 'last_seen_at', 'expires_at', 'revoked_at'])]
#[Hidden(['pairing_code_hash', 'token_hash'])]
class BrowserConnection extends Model
{
    use ResolvesWithinCurrentTeam;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

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

    /** @return HasMany<AutomationRun, $this> */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    protected function casts(): array
    {
        return [
            'status' => BrowserConnectionStatus::class,
            'allowed_origins' => 'array',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
