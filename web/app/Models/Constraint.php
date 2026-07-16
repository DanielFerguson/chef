<?php

namespace App\Models;

use App\Enums\ConstraintKind;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\ConstraintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $person_id
 * @property int|null $created_by_user_id
 * @property ConstraintKind $kind
 * @property string $subject
 * @property string|null $details
 * @property string|null $severity
 * @property Carbon $explicitly_confirmed_at
 */
#[Fillable(['team_id', 'person_id', 'created_by_user_id', 'kind', 'subject', 'details', 'severity', 'explicitly_confirmed_at'])]
class Constraint extends Model
{
    /** @use HasFactory<ConstraintFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ConstraintKind::class,
            'explicitly_confirmed_at' => 'datetime',
        ];
    }
}
