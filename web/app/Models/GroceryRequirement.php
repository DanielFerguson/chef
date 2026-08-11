<?php

namespace App\Models;

use App\Enums\GroceryRequirementStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\GroceryRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property GroceryRequirementStatus $status
 * @property float|null $quantity
 * @property bool $quantity_unknown
 * @property list<string> $search_queries
 * @property list<array<string, mixed>>|null $applicable_constraints
 */
#[Fillable(['team_id', 'grocery_plan_id', 'ingredient_id', 'status', 'display_name', 'normalized_name', 'normalized_form', 'quantity', 'unit', 'quantity_unknown', 'fingerprint', 'search_queries', 'applicable_constraints'])]
class GroceryRequirement extends Model
{
    /** @use HasFactory<GroceryRequirementFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<GroceryPlan, $this> */
    public function groceryPlan(): BelongsTo
    {
        return $this->belongsTo(GroceryPlan::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return HasMany<GroceryRequirementSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(GroceryRequirementSource::class);
    }

    /** @return HasMany<RetailerProductCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(RetailerProductCandidate::class);
    }

    /** @return HasOne<RetailerProductSelection, $this> */
    public function selection(): HasOne
    {
        return $this->hasOne(RetailerProductSelection::class);
    }

    /** @return HasMany<GroceryRequirementSearchAttempt, $this> */
    public function searchAttempts(): HasMany
    {
        return $this->hasMany(GroceryRequirementSearchAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'status' => GroceryRequirementStatus::class,
            'quantity' => 'float',
            'quantity_unknown' => 'boolean',
            'search_queries' => 'array',
            'applicable_constraints' => 'array',
        ];
    }
}
