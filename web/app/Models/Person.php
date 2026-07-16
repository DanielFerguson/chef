<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property-read Collection<int, Preference> $preferences
 * @property-read Collection<int, Constraint> $constraints
 */
#[Fillable(['team_id', 'created_by_user_id', 'source_message_id', 'name'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

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

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return HasOne<UserPersonLink, $this> */
    public function userLink(): HasOne
    {
        return $this->hasOne(UserPersonLink::class);
    }

    /** @return BelongsToMany<MealSlot, $this> */
    public function mealSlots(): BelongsToMany
    {
        return $this->belongsToMany(MealSlot::class, 'meal_slot_participants')
            ->withPivot('servings')
            ->withTimestamps();
    }

    /** @return HasMany<Preference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(Preference::class);
    }

    /** @return HasMany<Constraint, $this> */
    public function constraints(): HasMany
    {
        return $this->hasMany(Constraint::class);
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
