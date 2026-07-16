<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['team_id', 'created_by_user_id', 'idempotency_key', 'title', 'summary', 'source_url'])]
class Recipe extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<RecipeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    /** @return HasOne<RecipeVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class)->ofMany('version', 'max');
    }
}
