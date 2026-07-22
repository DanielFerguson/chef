<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by_user_id
 * @property int $version
 * @property string $currency
 * @property numeric-string|null $chef_subtotal
 * @property numeric-string|null $cart_total
 * @property string $checksum
 * @property Carbon $captured_at
 */
#[Fillable(['team_id', 'created_by_user_id', 'version', 'currency', 'chef_subtotal', 'cart_total', 'checksum', 'captured_at'])]
class CartSnapshot extends Model
{
    use ResolvesWithinCurrentTeam;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cart snapshots are immutable.'));
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<CartSnapshotLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CartSnapshotLine::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    protected function casts(): array
    {
        return [
            'chef_subtotal' => 'decimal:2',
            'cart_total' => 'decimal:2',
            'captured_at' => 'datetime',
        ];
    }
}
