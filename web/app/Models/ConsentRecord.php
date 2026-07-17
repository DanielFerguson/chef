<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $kind
 * @property string $status
 * @property string $purpose
 * @property array<string, mixed>|null $scope
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['team_id', 'user_id', 'kind', 'status', 'purpose', 'scope', 'occurred_at'])]
class ConsentRecord extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
