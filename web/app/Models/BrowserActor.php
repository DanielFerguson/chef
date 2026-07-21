<?php

namespace App\Models;

use App\Enums\BrowserActorStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $browser_session_id
 * @property int $generation
 * @property string $actor_uuid
 * @property string $fencing_token
 * @property string $socket_path
 * @property int|null $process_id
 * @property BrowserActorStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $stopped_at
 * @property array<string, mixed>|null $diagnostics
 */
#[Fillable(['team_id', 'browser_session_id', 'generation', 'actor_uuid', 'fencing_token', 'socket_path', 'process_id', 'status', 'started_at', 'connected_at', 'heartbeat_at', 'stopped_at', 'diagnostics'])]
#[Hidden(['fencing_token', 'socket_path', 'process_id'])]
class BrowserActor extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<BrowserSession, $this> */
    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class);
    }

    protected function casts(): array
    {
        return [
            'fencing_token' => 'encrypted',
            'status' => BrowserActorStatus::class,
            'started_at' => 'datetime',
            'connected_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'stopped_at' => 'datetime',
            'diagnostics' => 'array',
        ];
    }
}
