<?php

namespace App\Models;

use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Database\Factories\BrowserSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $retailer_connection_id
 * @property int|null $automation_run_id
 * @property string $provider_session_id
 * @property BrowserSessionPurpose $purpose
 * @property BrowserSessionStatus $status
 * @property bool $recording_enabled
 * @property Carbon|null $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $ended_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['team_id', 'retailer_connection_id', 'automation_run_id', 'provider_session_id', 'purpose', 'status', 'recording_enabled', 'started_at', 'expires_at', 'ended_at', 'metadata'])]
#[Hidden(['provider_session_id'])]
class BrowserSession extends Model
{
    /** @use HasFactory<BrowserSessionFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<RetailerConnection, $this> */
    public function retailerConnection(): BelongsTo
    {
        return $this->belongsTo(RetailerConnection::class);
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    protected function casts(): array
    {
        return [
            'provider_session_id' => 'encrypted',
            'purpose' => BrowserSessionPurpose::class,
            'status' => BrowserSessionStatus::class,
            'recording_enabled' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
