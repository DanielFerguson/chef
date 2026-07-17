<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int $shopping_list_id
 * @property int $shopping_list_revision
 * @property int $retailer_id
 * @property int $browser_connection_id
 * @property int|null $requested_by_user_id
 * @property AutomationRunStatus $status
 * @property array<string, mixed> $scope_snapshot
 * @property string|null $previous_response_id
 * @property string|null $current_tab_id
 * @property string|null $current_url
 * @property array{total: int, added: int, unresolved: int}|null $progress
 * @property string|null $pause_reason
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon $expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $takeover_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['uuid', 'team_id', 'shopping_list_id', 'shopping_list_revision', 'retailer_id', 'browser_connection_id', 'requested_by_user_id', 'status', 'execution_surface', 'scope_snapshot', 'previous_response_id', 'current_tab_id', 'current_url', 'progress', 'pause_reason', 'error_code', 'error_message', 'expires_at', 'started_at', 'takeover_at', 'finished_at'])]
class AutomationRun extends Model
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

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /** @return BelongsTo<BrowserConnection, $this> */
    public function browserConnection(): BelongsTo
    {
        return $this->belongsTo(BrowserConnection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<AutomationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('sequence');
    }

    /** @return HasMany<AutomationApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(AutomationApproval::class)->latest();
    }

    /** @return HasMany<AutomationReconciliation, $this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(AutomationReconciliation::class)->orderBy('id');
    }

    /** @return array<int, array<string, mixed>> */
    public function scopeItems(): array
    {
        $items = $this->scope_snapshot['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, is_array(...)));
    }

    public function approvedBudget(): ?float
    {
        $budget = $this->scope_snapshot['approved_budget'] ?? null;

        return is_numeric($budget) ? (float) $budget : null;
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'scope_snapshot' => 'array',
            'progress' => 'array',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'takeover_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
