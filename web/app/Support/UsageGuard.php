<?php

namespace App\Support;

use App\Models\AutomationRun;
use App\Models\OperationalEvent;
use App\Models\Team;
use App\Models\VoiceSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class UsageGuard
{
    public function assertAiAllowed(Team $team): void
    {
        $key = "ai:team:{$team->id}";
        $perMinute = (int) config('chef.quotas.ai_requests_per_minute', 12);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw ValidationException::withMessages(['usage' => 'Chef is receiving too many AI requests for this family. Wait a minute and try again.']);
        }

        $events = OperationalEvent::query()
            ->where('team_id', $team->id)
            ->where('category', 'ai')
            ->where('occurred_at', '>=', now()->startOfMonth());
        $tokens = (clone $events)->sum('prompt_tokens') + (clone $events)->sum('completion_tokens');
        $costMicrousd = (clone $events)->sum('estimated_cost_microusd');

        if ($tokens >= (int) config('chef.quotas.ai_tokens_per_month', 2_000_000)) {
            throw ValidationException::withMessages(['usage' => 'This family has reached its monthly AI allowance. Contact support for help.']);
        }

        if ($costMicrousd >= (int) round(((float) config('chef.quotas.ai_cost_usd_per_month', 25)) * 1_000_000)) {
            throw ValidationException::withMessages(['usage' => 'This family has reached its monthly AI cost ceiling. Contact support for help.']);
        }

        RateLimiter::hit($key, 60);
    }

    public function assertAutomationAllowed(Team $team): void
    {
        $runs = AutomationRun::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($runs >= (int) config('chef.quotas.automation_runs_per_day', 10)) {
            throw ValidationException::withMessages(['usage' => 'This family has reached today’s cart-preparation allowance. Try again tomorrow.']);
        }
    }

    public function assertAutomationStepAllowed(AutomationRun $run): void
    {
        if ($run->steps()->count() >= (int) config('chef.quotas.automation_steps_per_run', 100)) {
            throw ValidationException::withMessages(['usage' => 'Cart preparation reached its safety step limit. Take over in the retailer tab.']);
        }

        $this->assertAiAllowed($run->team);
    }

    public function assertVoiceAllowed(Team $team): void
    {
        $sessions = VoiceSession::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($sessions >= (int) config('chef.quotas.voice_sessions_per_day', 20)) {
            throw ValidationException::withMessages(['usage' => 'This family has reached today’s voice-session allowance. Typed Chef remains available.']);
        }
    }
}
