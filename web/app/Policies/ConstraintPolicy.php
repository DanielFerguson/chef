<?php

namespace App\Policies;

use App\Models\Constraint;
use App\Models\User;

class ConstraintPolicy
{
    public function update(User $user, Constraint $constraint): bool
    {
        return $user->memberships()->where('team_id', $constraint->team_id)->exists();
    }

    public function delete(User $user, Constraint $constraint): bool
    {
        return $this->update($user, $constraint);
    }
}
