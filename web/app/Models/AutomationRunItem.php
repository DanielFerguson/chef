<?php

namespace App\Models;

use App\Enums\AutomationRunItemStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\AutomationRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int|null $shopping_list_item_id
 * @property int $position
 * @property AutomationRunItemStatus $status
 * @property array<string, mixed> $requirement_snapshot
 * @property array<string, mixed>|null $matched_product
 * @property int $attempts
 * @property string|null $failure_message
 * @property Carbon|null $resolved_at
 */
#[Fillable(['team_id', 'automation_run_id', 'shopping_list_item_id', 'position', 'status', 'requirement_snapshot', 'matched_product', 'attempts', 'failure_message', 'resolved_at'])]
class AutomationRunItem extends Model
{
    /** @use HasFactory<AutomationRunItemFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    /** @return HasMany<AutomationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class);
    }

    /** @return HasMany<AutomationIntervention, $this> */
    public function interventions(): HasMany
    {
        return $this->hasMany(AutomationIntervention::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationRunItemStatus::class,
            'requirement_snapshot' => 'array',
            'matched_product' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
