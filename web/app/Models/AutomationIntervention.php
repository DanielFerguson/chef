<?php

namespace App\Models;

use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\AutomationInterventionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $automation_run_id
 * @property int|null $automation_run_item_id
 * @property int|null $browser_session_id
 * @property int|null $requested_by_user_id
 * @property int|null $resolved_by_user_id
 * @property AutomationInterventionType $type
 * @property AutomationInterventionStatus $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $resolution
 * @property Carbon $requested_at
 * @property Carbon|null $resolved_at
 */
#[Fillable(['team_id', 'automation_run_id', 'automation_run_item_id', 'browser_session_id', 'requested_by_user_id', 'resolved_by_user_id', 'type', 'status', 'payload', 'resolution', 'requested_at', 'resolved_at'])]
class AutomationIntervention extends Model
{
    /** @use HasFactory<AutomationInterventionFactory> */
    use HasFactory;

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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => AutomationInterventionType::class,
            'status' => AutomationInterventionStatus::class,
            'payload' => 'array',
            'resolution' => 'array',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
