<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'recipe_id', 'created_by_user_id', 'version', 'title', 'summary', 'servings', 'prep_minutes', 'cook_minutes', 'source_url', 'notes', 'storage_guidance', 'published_at'])]
class RecipeVersion extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return HasMany<RecipeIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    /** @return HasMany<RecipeStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    /** @return HasMany<RecipeEquipment, $this> */
    public function equipment(): HasMany
    {
        return $this->hasMany(RecipeEquipment::class)->orderBy('position');
    }

    /** @return HasMany<RecipePreparationNotice, $this> */
    public function preparationNotices(): HasMany
    {
        return $this->hasMany(RecipePreparationNotice::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['servings' => 'float', 'published_at' => 'datetime'];
    }
}
