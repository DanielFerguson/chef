<?php

namespace App\Models;

use App\Enums\RetailerOrderRunStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\RetailerOrderRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int $shopping_list_id
 * @property int $shopping_list_revision_id
 * @property int $retailer_connection_id
 * @property int $started_by_user_id
 * @property int $cart_product_plan_id
 * @property RetailerOrderRunStatus $status
 * @property string|null $fulfilment_type
 * @property array<string, mixed>|null $fulfilment_options
 * @property Carbon|null $fulfilment_options_expires_at
 * @property array<string, mixed>|null $selected_slot
 * @property array<string, mixed>|null $confirmation
 * @property string|null $cart_checksum
 * @property string|null $confirmation_fingerprint
 * @property string|null $retailer_order_reference
 * @property string|null $failure_message
 * @property array<string, mixed>|null $limits
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'team_id',
    'shopping_list_id',
    'shopping_list_revision_id',
    'retailer_connection_id',
    'started_by_user_id',
    'cart_product_plan_id',
    'status',
    'fulfilment_type',
    'fulfilment_options',
    'fulfilment_options_expires_at',
    'selected_slot',
    'confirmation',
    'cart_checksum',
    'confirmation_fingerprint',
    'retailer_order_reference',
    'failure_message',
    'limits',
    'expires_at',
])]
class RetailerOrderRun extends Model
{
    /** @use HasFactory<RetailerOrderRunFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<ShoppingListRevision, $this> */
    public function shoppingListRevision(): BelongsTo
    {
        return $this->belongsTo(ShoppingListRevision::class);
    }

    /** @return BelongsTo<RetailerConnection, $this> */
    public function retailerConnection(): BelongsTo
    {
        return $this->belongsTo(RetailerConnection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** @return BelongsTo<CartProductPlan, $this> */
    public function cartProductPlan(): BelongsTo
    {
        return $this->belongsTo(CartProductPlan::class);
    }

    /** @return HasMany<RetailerOrderRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RetailerOrderRunItem::class)->orderBy('position');
    }

    /** @return HasMany<RetailerOrderStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RetailerOrderStep::class)->orderBy('sequence');
    }

    protected function casts(): array
    {
        return [
            'status' => RetailerOrderRunStatus::class,
            'fulfilment_options' => 'array',
            'fulfilment_options_expires_at' => 'datetime',
            'selected_slot' => 'array',
            'confirmation' => 'array',
            'limits' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
