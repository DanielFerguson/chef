<?php

namespace App\Actions\Households;

use App\Enums\ConstraintKind;
use App\Models\Constraint;
use App\Models\Message;
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
        ?Message $confirmationMessage = null,
        bool $directlyConfirmed = false,
        ?Person $person = null,
        ?string $details = null,
        ?string $severity = null,
    ): Constraint {
        if (! $user->memberships()->whereBelongsTo($team)->exists() || ($person !== null && $person->team_id !== $team->id)) {
            throw new AuthorizationException('You cannot record a constraint for this family.');
        }

        if ($confirmationMessage !== null && (
            $confirmationMessage->team_id !== $team->id
            || $confirmationMessage->user_id !== $user->id
            || $confirmationMessage->role->value !== 'user'
        )) {
            throw new AuthorizationException('That message cannot confirm a safety constraint for this family.');
        }

        if ($confirmationMessage === null && ! $directlyConfirmed) {
            throw ValidationException::withMessages(['explicitly_confirmed' => 'Safety constraints must be explicitly confirmed by a person.']);
        }

        $identity = ['team_id' => $team->id, 'person_id' => $person?->id, 'kind' => $kind, 'subject' => $subject];
        $values = [
            'created_by_user_id' => $user->id,
            'confirmation_message_id' => $confirmationMessage?->id,
            'details' => $details,
            'severity' => $severity,
            'explicitly_confirmed_at' => now(),
        ];
        $existing = Constraint::query()->where($identity)->first();

        if ($existing !== null) {
            $existing->update($values);

            return $existing;
        }

        $personId = $person === null ? '' : (string) $person->id;
        $idempotencyKey = $confirmationMessage === null ? null : hash('sha256', implode('|', [
            'constraint', $team->id, $confirmationMessage->id, $personId, $kind->value, mb_strtolower(trim($subject)),
        ]));

        if ($confirmationMessage === null) {
            return Constraint::query()->updateOrCreate($identity, $values);
        }

        return Constraint::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [...$identity, ...$values],
        );
    }
}
