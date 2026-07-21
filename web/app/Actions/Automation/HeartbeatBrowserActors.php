<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\ComputerExecutor;
use App\Enums\BrowserActorStatus;
use App\Enums\BrowserSessionStatus;
use App\Models\BrowserActor;
use Throwable;

class HeartbeatBrowserActors
{
    public function __construct(private readonly ComputerExecutor $executor) {}

    /** @return array{checked: int, healthy: int, recovered: int, lost: int} */
    public function handle(): array
    {
        $counts = ['checked' => 0, 'healthy' => 0, 'recovered' => 0, 'lost' => 0];
        $threshold = now()->subSeconds(max(10, (int) config('services.chef_automation.actor_heartbeat_ttl', 90) / 2));
        $activeActorStatuses = collect(BrowserActorStatus::cases())->filter->isActive()->map->value->all();

        BrowserActor::query()
            ->whereIn('status', $activeActorStatuses)
            ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<=', $threshold))
            ->whereHas('browserSession', fn ($query) => $query
                ->whereIn('status', [
                    BrowserSessionStatus::AgentControl->value,
                    BrowserSessionStatus::HumanControl->value,
                ])
                ->where(fn ($sessionQuery) => $sessionQuery->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->with('browserSession.latestActor')
            ->orderBy('id')
            ->each(function (BrowserActor $actor) use (&$counts): void {
                if ($actor->browserSession->latestActor?->id !== $actor->id) {
                    $actor->update(['status' => BrowserActorStatus::Fenced, 'stopped_at' => now()]);

                    return;
                }

                $counts['checked']++;

                try {
                    $result = $this->executor->heartbeat($actor->browserSession);
                    $counts[(bool) ($result->diagnostics['actor_recovered'] ?? false) ? 'recovered' : 'healthy']++;
                } catch (Throwable) {
                    $counts['lost']++;
                    $this->executor->stop($actor->browserSession);
                }
            });

        return $counts;
    }
}
