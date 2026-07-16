<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 */
#[Fillable(['team_id', 'name'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasOne<UserPersonLink, $this> */
    public function userLink(): HasOne
    {
        return $this->hasOne(UserPersonLink::class);
    }

    /**
     * Keep implicit person bindings inside the signed-in user's active family.
     */
    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $activeTeamId = auth()->check() ? auth()->user()->current_team_id : 0;

        return $query
            ->where('team_id', $activeTeamId)
            ->where($field ?? $this->getRouteKeyName(), $value);
    }
}
