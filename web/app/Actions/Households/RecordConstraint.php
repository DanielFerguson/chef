<?php

namespace App\Actions\Households;

use App\Enums\ConstraintKind;
use App\Models\Constraint;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RecordConstraint
{
    public function handle(
        Team $team,
        User $user,
        ConstraintKind $kind,
        string $subject,
        bool $explicitlyConfirmed,
        ?Person $person = null,
        ?string $details = null,
        ?string $severity = null,
    ): Constraint {
        if (! $user->memberships()->whereBelongsTo($team)->exists() || ($person !== null && $person->team_id !== $team->id)) {
            throw new AuthorizationException('You cannot record a constraint for this family.');
        }

        if (! $explicitlyConfirmed) {
            throw ValidationException::withMessages(['explicitly_confirmed' => 'Safety constraints must be explicitly confirmed by a person.']);
        }

        return Constraint::query()->updateOrCreate(
            ['team_id' => $team->id, 'person_id' => $person?->id, 'kind' => $kind, 'subject' => $subject],
            [
                'created_by_user_id' => $user->id,
                'details' => $details,
                'severity' => $severity,
                'explicitly_confirmed_at' => now(),
            ],
        );
    }
}
