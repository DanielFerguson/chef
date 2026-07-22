<?php

namespace App\Models;

use App\Enums\RetailerConnectionStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\RetailerConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int $retailer_id
 * @property int $owner_user_id
 * @property string $provider
 * @property string|null $provider_context_id
 * @property RetailerConnectionStatus $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $lease_expires_at
 * @property string|null $lease_owner
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['team_id', 'retailer_id', 'owner_user_id', 'provider', 'provider_context_id', 'status', 'last_verified_at', 'disconnected_at', 'lease_expires_at', 'lease_owner', 'metadata'])]
#[Hidden(['provider_context_id', 'lease_owner'])]
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

    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** @return HasMany<RetailerOrderRun, $this> */
    public function orderRuns(): HasMany
    {
        return $this->hasMany(RetailerOrderRun::class);
    }

    /** @return HasMany<BrowserSession, $this> */
    public function browserSessions(): HasMany
    {
        return $this->hasMany(BrowserSession::class);
    }

    protected function casts(): array
    {
        return [
            'provider_context_id' => 'encrypted',
            'status' => RetailerConnectionStatus::class,
            'last_verified_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
