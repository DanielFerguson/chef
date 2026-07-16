<?php

namespace App\Actions\Households;

use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class CreateHouseholdPerson
{
    public function handle(Team $team, User $user, string $name, Message $sourceMessage): Person
    {
        if (! $user->memberships()->whereBelongsTo($team)->exists()
            || $sourceMessage->team_id !== $team->id
            || $sourceMessage->user_id !== $user->id
            || $sourceMessage->role !== MessageRole::User) {
            throw new AuthorizationException('You cannot add a person to this family from that message.');
        }

        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the person’s name.']);
        }

        $existing = $team->people()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $team->people()->firstOrCreate(
            ['source_message_id' => $sourceMessage->id, 'name' => $name],
            ['created_by_user_id' => $user->id],
        );
    }
}
