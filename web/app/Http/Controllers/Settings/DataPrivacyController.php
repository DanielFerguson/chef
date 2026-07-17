<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\OperationalEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataPrivacyController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_if($team === null, 404);

        $consents = [];
        $records = ConsentRecord::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereIn('kind', ['product_analytics', 'beta_research'])
            ->latest('occurred_at')
            ->get();

        foreach ($records as $record) {
            if (! array_key_exists($record->kind, $consents)) {
                $consents[$record->kind] = [
                    'status' => $record->status,
                    'occurred_at' => $record->occurred_at->toIso8601String(),
                ];
            }
        }

        $monthEvents = OperationalEvent::query()
            ->where('team_id', $team->id)
            ->where('occurred_at', '>=', now()->startOfMonth());
        $recentAutomation = OperationalEvent::query()
            ->where('team_id', $team->id)
            ->where('category', 'automation')
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (OperationalEvent $event): array => [
                'name' => $event->name,
                'status' => $event->status,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'metadata' => $event->metadata,
            ]);

        return Inertia::render('settings/data-privacy', [
            'team' => $team->only(['id', 'name']),
            'consents' => $consents,
            'permissions' => [
                'active_browser_connections' => $team->browserConnections()->where('status', 'active')->count(),
                'active_voice_sessions' => $team->voiceSessions()->whereIn('status', ['connecting', 'active'])->count(),
            ],
            'canDeleteTeam' => $user->can('delete', $team),
            'support' => config('chef.support'),
            'retention' => [
                'conversation_days' => config('chef.retention.conversations_days'),
                'screenshot_hours' => config('chef.retention.automation_screenshots_hours'),
                'audit_days' => config('chef.retention.audit_days'),
            ],
            'usage' => [
                'ai_requests' => (clone $monthEvents)->where('category', 'ai')->count(),
                'tokens' => (clone $monthEvents)->sum('prompt_tokens') + (clone $monthEvents)->sum('completion_tokens'),
                'estimated_cost_usd' => round(((int) (clone $monthEvents)->sum('estimated_cost_microusd')) / 1_000_000, 4),
                'automation_runs' => (clone $monthEvents)->where('name', 'cart_preparation_started')->count(),
                'recent_automation' => $recentAutomation,
            ],
            'quotas' => [
                'tokens_per_month' => config('chef.quotas.ai_tokens_per_month'),
                'cost_usd_per_month' => config('chef.quotas.ai_cost_usd_per_month'),
                'automation_runs_per_day' => config('chef.quotas.automation_runs_per_day'),
                'voice_sessions_per_day' => config('chef.quotas.voice_sessions_per_day'),
            ],
        ]);
    }
}
