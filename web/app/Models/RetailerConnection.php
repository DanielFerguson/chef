<?php

namespace App\Models;

use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property RetailerProvider $provider
 * @property RetailerConnectionStatus $status
 * @property string|null $browserbase_context_id
 * @property string|null $active_session_id
 * @property string|null $active_session_claim_token
 * @property string|null $active_session_purpose
 * @property Carbon|null $active_session_started_at
 * @property Carbon|null $active_session_expires_at
 * @property Carbon|null $authenticated_at
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $disconnected_at
 */
#[Fillable(['team_id', 'owner_user_id', 'provider', 'status', 'browserbase_context_id', 'context_lookup_hash', 'active_session_id', 'active_session_claim_token', 'active_session_purpose', 'active_session_started_at', 'active_session_expires_at', 'authenticated_at', 'last_verified_at', 'disconnected_at', 'failure_code', 'failure_message'])]
#[Hidden(['browserbase_context_id', 'context_lookup_hash', 'active_session_id', 'active_session_claim_token'])]
class RetailerConnection extends Model
{
    /** @use HasFactory<RetailerConnectionFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<RetailerAutomationGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(RetailerAutomationGrant::class);
    }

    /** @return HasMany<BasketRun, $this> */
    public function basketRuns(): HasMany
    {
        return $this->hasMany(BasketRun::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => RetailerProvider::class,
            'status' => RetailerConnectionStatus::class,
            'browserbase_context_id' => 'encrypted',
            'active_session_id' => 'encrypted',
            'active_session_started_at' => 'datetime',
            'active_session_expires_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }
}
