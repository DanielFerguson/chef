<?php

namespace App\Models;

use App\Enums\PreparationNoticeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recipe_version_id', 'position', 'kind', 'instruction', 'lead_minutes'])]
class RecipePreparationNotice extends Model
{
    /** @return BelongsTo<RecipeVersion, $this> */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    protected function casts(): array
    {
        return ['kind' => PreparationNoticeKind::class];
    }
}
