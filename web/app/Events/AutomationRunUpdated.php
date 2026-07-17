<?php

namespace App\Events;

use App\Models\AutomationRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutomationRunUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AutomationRun $run) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('teams.'.$this->run->team_id);
    }

    public function broadcastAs(): string
    {
        return 'automation.run.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $reconciliation = $this->run->reconciliations()
            ->get(['id', 'status', 'intended_name', 'product_name', 'substitution_reason'])
            ->map(fn ($line) => [
                'id' => $line->id,
                'status' => $line->status->value,
                'intended_name' => $line->intended_name,
                'product_name' => $line->product_name,
                'substitution_reason' => $line->substitution_reason,
            ])
            ->values()
            ->all();

        return [
            'run_id' => $this->run->uuid,
            'status' => $this->run->status->value,
            'progress' => $this->run->progress,
            'pause_reason' => $this->run->pause_reason,
            'failure' => $this->run->error_code === null ? null : [
                'code' => $this->run->error_code,
                'message' => $this->run->error_message,
            ],
            'pending_approvals' => $this->run->approvals()->where('status', 'pending')->count(),
            'reconciliation' => $reconciliation,
            'updated_at' => $this->run->updated_at?->toIso8601String(),
        ];
    }
}
