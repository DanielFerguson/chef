<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $timezone
 */
#[Fillable(['name', 'timezone'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /** @return HasMany<TeamMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Person, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** @return HasMany<MealPlan, $this> */
    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
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
}
