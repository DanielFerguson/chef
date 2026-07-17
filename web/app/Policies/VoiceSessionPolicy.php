<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VoiceSession;

class VoiceSessionPolicy
{
    public function update(User $user, VoiceSession $session): bool
    {
        return $session->user_id === $user->id
            && $user->current_team_id === $session->team_id
            && $user->can('update', $session->conversation);
    }

    public function delete(User $user, VoiceSession $session): bool
    {
        return $this->update($user, $session);
    }
}
