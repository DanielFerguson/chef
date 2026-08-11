<?php

namespace App\Models;

use App\Enums\MealSlotKind;
use App\Enums\MealSlotParticipantOrigin;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\MealSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $meal_plan_id
 * @property Carbon $date
 * @property MealSlotKind $kind
 * @property string|null $label
 * @property int $position
 * @property string|null $notes
 * @property MealSlotParticipantOrigin $participant_assignment_origin
 * @property int|null $participant_source_meal_slot_id
 * @property Carbon|null $participant_defaults_applied_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'source_message_id', 'idempotency_key', 'date', 'kind', 'label', 'position', 'notes', 'participant_assignment_origin', 'participant_source_meal_slot_id', 'participant_defaults_applied_at'])]
class MealSlot extends Model
{
    /** @use HasFactory<MealSlotFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function participantSourceSlot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'participant_source_meal_slot_id');
    }

    /** @return BelongsToMany<Person, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'meal_slot_participants')
            ->withPivot('servings')
            ->withTimestamps();
    }

    /** @return HasOne<PlannedMeal, $this> */
    public function plannedMeal(): HasOne
    {
        return $this->hasOne(PlannedMeal::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'kind' => MealSlotKind::class,
            'participant_assignment_origin' => MealSlotParticipantOrigin::class,
            'participant_defaults_applied_at' => 'datetime',
        ];
    }
}
