<?php

namespace App\Actions\Households;

use App\Actions\MealPlans\InvalidateMealPlansForConstraintChange;
use App\Models\Constraint;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateConstraint
{
    public function __construct(private readonly InvalidateMealPlansForConstraintChange $invalidatePlans) {}

    public function handle(Constraint $constraint, User $user, string $subject, ?string $details, ?string $severity): Constraint
    {
        if (! $user->can('update', $constraint)) {
            throw new AuthorizationException('You cannot update this safety constraint.');
        }

        $subject = trim($subject);
        $changed = $constraint->subject !== $subject
            || $constraint->details !== $details
            || $constraint->severity !== $severity;

        if (! $changed) {
            return $constraint;
        }

        $updated = DB::transaction(function () use ($constraint, $details, $severity, $subject, $user): Constraint {
            $target = Constraint::query()
                ->where('team_id', $constraint->team_id)
                ->where('person_id', $constraint->person_id)
                ->where('kind', $constraint->kind)
                ->where('subject', $subject)
                ->whereKeyNot($constraint->id)
                ->first();
            $values = [
                'created_by_user_id' => $user->id,
                'confirmation_message_id' => null,
                'details' => $details,
                'severity' => $severity,
                'explicitly_confirmed_at' => now(),
            ];

            if ($target !== null) {
                $target->update($values);
                $constraint->delete();

                return $target->refresh();
            }

            $constraint->update([...$values, 'subject' => $subject]);

            return $constraint->refresh();
        });

        $this->invalidatePlans->handle(
            $updated->team,
            $user,
            $updated->person,
            'Updated a confirmed household safety constraint.',
        );

        return $updated;
    }
}
