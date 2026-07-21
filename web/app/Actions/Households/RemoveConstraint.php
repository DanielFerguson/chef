<?php

namespace App\Actions\Households;

use App\Actions\MealPlans\InvalidateMealPlansForConstraintChange;
use App\Models\Constraint;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RemoveConstraint
{
    public function __construct(private readonly InvalidateMealPlansForConstraintChange $invalidatePlans) {}

    public function handle(Constraint $constraint, User $user): void
    {
        if (! $user->can('delete', $constraint)) {
            throw new AuthorizationException('You cannot remove this safety constraint.');
        }

        $team = $constraint->team;
        $person = $constraint->person;
        $constraint->delete();
        $this->invalidatePlans->handle(
            $team,
            $user,
            $person,
            'Removed a confirmed household safety constraint.',
        );
    }
}
