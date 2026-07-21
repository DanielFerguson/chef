<?php

namespace App\Actions\Automation;

use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Models\AutomationIntervention;
use App\Models\AutomationRun;
use App\Models\AutomationRunItem;
use App\Models\BrowserSession;
use App\Models\User;

class CreateAutomationIntervention
{
    /** @param array<string, mixed> $payload */
    public function handle(
        AutomationRun $run,
        AutomationInterventionType $type,
        array $payload,
        ?AutomationRunItem $item = null,
        ?BrowserSession $session = null,
        ?User $requester = null,
    ): AutomationIntervention {
        $existing = $run->interventions()
            ->where('type', $type->value)
            ->where('status', AutomationInterventionStatus::Pending->value)
            ->when($item !== null, fn ($query) => $query->where('automation_run_item_id', $item->id))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $run->interventions()->create([
            'team_id' => $run->team_id,
            'automation_run_item_id' => $item?->id,
            'browser_session_id' => $session?->id,
            'requested_by_user_id' => $requester?->id,
            'type' => $type,
            'status' => AutomationInterventionStatus::Pending,
            'payload' => $payload,
            'requested_at' => now(),
        ]);
    }
}
