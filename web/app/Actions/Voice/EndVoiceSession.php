<?php

namespace App\Actions\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Auth\Access\AuthorizationException;

class EndVoiceSession
{
    public function handle(VoiceSession $session, User $user): VoiceSession
    {
        if ($session->user_id !== $user->id || ! $user->can('update', $session->conversation)) {
            throw new AuthorizationException('You cannot end this voice session.');
        }

        if ($session->status === VoiceSessionStatus::Ended) {
            return $session;
        }

        $session->update([
            'status' => VoiceSessionStatus::Ended,
            'microphone_permission_revoked_at' => now(),
            'ended_at' => now(),
        ]);

        return $session;
    }
}
