<?php

namespace App\Actions\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VoiceSession;
use App\Support\OperationalMetrics;
use App\Support\UsageGuard;
use App\Voice\Contracts\RealtimeSessionBroker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Throwable;

class StartVoiceSession
{
    public function __construct(
        private readonly RealtimeSessionBroker $broker,
        private readonly UsageGuard $usageGuard,
        private readonly OperationalMetrics $metrics,
    ) {}

    /** @return array{session: VoiceSession, answer_sdp: string} */
    public function handle(Conversation $conversation, User $user, string $offerSdp): array
    {
        if (! $user->can('update', $conversation)) {
            throw new AuthorizationException('You cannot start voice for this conversation.');
        }

        $team = $conversation->team()->firstOrFail();
        $this->usageGuard->assertVoiceAllowed($team);

        $session = VoiceSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'team_id' => $conversation->team_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => VoiceSessionStatus::Connecting,
            'model' => config('services.openai.realtime.model'),
            'voice' => config('services.openai.realtime.voice'),
            'microphone_permission_granted_at' => now(),
            'expires_at' => now()->addMinutes(60),
        ]);

        try {
            $answerSdp = $this->broker->connect($session, $offerSdp);
            $session->update([
                'status' => VoiceSessionStatus::Active,
                'connected_at' => now(),
            ]);
            $this->metrics->recordAi(
                $team,
                $user,
                'realtime_session',
                'active',
                null,
                'openai',
                $session->model,
                null,
                0,
                'voice_session',
                $session->id,
            );
        } catch (Throwable $exception) {
            $session->update([
                'status' => VoiceSessionStatus::Failed,
                'last_error' => 'Realtime session creation failed.',
                'microphone_permission_revoked_at' => now(),
                'ended_at' => now(),
            ]);
            $this->metrics->recordAi(
                $team,
                $user,
                'realtime_session',
                'failed',
                null,
                'openai',
                $session->model,
                null,
                0,
                'voice_session',
                $session->id,
            );

            throw $exception;
        }

        return [
            'session' => $session,
            'answer_sdp' => $answerSdp,
        ];
    }
}
