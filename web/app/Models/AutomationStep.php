<?php

namespace App\Models;

use App\Enums\AutomationStepStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int $sequence
 * @property string|null $response_id
 * @property string|null $call_id
 * @property AutomationStepStatus $status
 * @property array<int, array<string, mixed>>|null $actions
 * @property array<int, array<string, mixed>>|null $safety_checks
 * @property array<string, mixed>|null $result
 * @property string|null $current_url
 * @property string|null $screenshot_path
 * @property Carbon|null $screenshot_expires_at
 * @property Carbon|null $requested_at
 * @property Carbon|null $executed_at
 * @property string|null $error_message
 */
#[Fillable(['team_id', 'automation_run_id', 'sequence', 'response_id', 'call_id', 'status', 'actions', 'safety_checks', 'result', 'current_url', 'screenshot_path', 'screenshot_expires_at', 'requested_at', 'executed_at', 'error_message'])]
class AutomationStep extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<AutomationRun, $this> */
    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    /** @return HasMany<AutomationApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(AutomationApproval::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationStepStatus::class,
            'actions' => 'array',
            'safety_checks' => 'array',
            'result' => 'array',
            'screenshot_expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
