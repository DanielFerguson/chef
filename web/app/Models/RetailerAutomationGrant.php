<?php

namespace App\Models;

use App\Enums\RetailerAutomationScope;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerAutomationGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property RetailerAutomationScope $scope
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['team_id', 'retailer_connection_id', 'owner_user_id', 'scope', 'disclosure_version', 'disclosure_hash', 'granted_at', 'revoked_at'])]
class RetailerAutomationGrant extends Model
{
    /** @use HasFactory<RetailerAutomationGrantFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<RetailerConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(RetailerConnection::class, 'retailer_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    protected function casts(): array
    {
        return [
            'scope' => RetailerAutomationScope::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
