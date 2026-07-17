<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $planning_confirmed_at
 */
#[Fillable(['team_id', 'created_by_user_id', 'title', 'starts_on', 'ends_on', 'revision', 'planning_confirmed_at', 'derived_data_stale_at', 'derived_data_stale_reason'])]
class MealPlan extends Model
{
    /** @use HasFactory<MealPlanFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

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

    /** @return HasMany<MealSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(MealSlot::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<MealProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(MealProposal::class);
    }

    /** @return HasMany<PlannedMeal, $this> */
    public function plannedMeals(): HasMany
    {
        return $this->hasMany(PlannedMeal::class);
    }

    /** @return HasManyThrough<PlannedMealRecipePreparation, PlannedMeal, $this> */
    public function recipePreparations(): HasManyThrough
    {
        return $this->hasManyThrough(PlannedMealRecipePreparation::class, PlannedMeal::class);
    }

    /** @return HasMany<MealPlanRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(MealPlanRevision::class)->orderByDesc('revision');
    }

    /** @return HasMany<MealPlanMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(MealPlanMilestone::class);
    }

    /** @return HasOne<ShoppingList, $this> */
    public function shoppingList(): HasOne
    {
        return $this->hasOne(ShoppingList::class);
    }

    /** @return HasOne<Budget, $this> */
    public function budget(): HasOne
    {
        return $this->hasOne(Budget::class);
    }

    /** @return HasMany<MealOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(MealOutcome::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'planning_confirmed_at' => 'datetime',
            'derived_data_stale_at' => 'datetime',
        ];
    }
}
