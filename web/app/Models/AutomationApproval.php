<?php

namespace App\Models;

use App\Enums\AutomationApprovalStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int|null $automation_step_id
 * @property string $risk_kind
 * @property string $proposed_action
 * @property string $consequence
 * @property AutomationApprovalStatus $status
 * @property int|null $decided_by_user_id
 * @property string|null $decision_note
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 */
#[Fillable(['team_id', 'automation_run_id', 'automation_step_id', 'risk_kind', 'proposed_action', 'consequence', 'status', 'decided_by_user_id', 'decision_note', 'expires_at', 'decided_at'])]
class AutomationApproval extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<AutomationRun, $this> */
    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    /** @return BelongsTo<AutomationStep, $this> */
    public function automationStep(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationApprovalStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
