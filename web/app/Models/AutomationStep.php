<?php

namespace App\Models;

use App\Enums\AutomationPolicyDecision;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int|null $automation_run_item_id
 * @property int|null $browser_session_id
 * @property int $sequence
 * @property string $action_type
 * @property AutomationPolicyDecision $policy_decision
 * @property array<string, mixed>|null $input_summary
 * @property array<string, mixed>|null $output_summary
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
#[Fillable(['team_id', 'automation_run_id', 'automation_run_item_id', 'browser_session_id', 'sequence', 'action_type', 'policy_decision', 'input_summary', 'output_summary', 'started_at', 'completed_at'])]
class AutomationStep extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /** @return BelongsTo<AutomationRunItem, $this> */
    public function runItem(): BelongsTo
    {
        return $this->belongsTo(AutomationRunItem::class, 'automation_run_item_id');
    }

    /** @return BelongsTo<BrowserSession, $this> */
    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class);
    }

    protected function casts(): array
    {
        return [
            'policy_decision' => AutomationPolicyDecision::class,
            'input_summary' => 'array',
            'output_summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
