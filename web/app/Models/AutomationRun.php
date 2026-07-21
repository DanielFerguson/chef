<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Enums\ExistingCartDecision;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\AutomationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $team_id
 * @property int $shopping_list_id
 * @property int $shopping_list_revision_id
 * @property int $retailer_connection_id
 * @property int $started_by_user_id
 * @property AutomationRunStatus $status
 * @property ExistingCartDecision|null $existing_cart_decision
 * @property array<string, mixed> $frozen_snapshot
 * @property array<string, int> $limits
 * @property string|null $openai_response_id
 * @property int $current_sequence
 * @property int $actions_taken
 * @property string|null $failure_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 */
#[Fillable(['team_id', 'shopping_list_id', 'shopping_list_revision_id', 'retailer_connection_id', 'started_by_user_id', 'status', 'existing_cart_decision', 'idempotency_key', 'frozen_snapshot', 'frozen_snapshot_checksum', 'limits', 'openai_response_id', 'current_sequence', 'actions_taken', 'failure_message', 'started_at', 'finished_at', 'expires_at'])]
#[Hidden(['openai_response_id', 'idempotency_key'])]
class AutomationRun extends Model
{
    /** @use HasFactory<AutomationRunFactory> */
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

    /** @return HasMany<AutomationRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AutomationRunItem::class)->orderBy('position');
    }

    /** @return HasMany<BrowserSession, $this> */
    public function browserSessions(): HasMany
    {
        return $this->hasMany(BrowserSession::class);
    }

    /** @return HasMany<AutomationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('sequence');
    }

    /** @return HasMany<AutomationIntervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(AutomationIntervention::class)->latest('requested_at');
    }

    /** @return HasMany<CartSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CartSnapshot::class)->orderByDesc('version');
    }

    /** @return HasOne<CartSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(CartSnapshot::class)->ofMany('version', 'max');
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'existing_cart_decision' => ExistingCartDecision::class,
            'frozen_snapshot' => 'array',
            'limits' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
