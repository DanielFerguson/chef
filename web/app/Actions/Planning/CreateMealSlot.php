<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\Message;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMealSlot
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    /** @param iterable<Person> $participants */
    public function handle(
        MealPlan $mealPlan,
        User $user,
        CarbonInterface $date,
        MealSlotKind $kind,
        iterable $participants,
        ?string $label = null,
        ?Message $sourceMessage = null,
    ): MealSlot {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        if ($date->isBefore($mealPlan->starts_on) || $date->isAfter($mealPlan->ends_on)) {
            throw ValidationException::withMessages(['date' => 'The meal must fall inside the plan date range.']);
        }

        $people = collect($participants);

        if ($people->contains(fn (Person $person) => $person->team_id !== $mealPlan->team_id)) {
            throw new AuthorizationException('Every participant must belong to the same family as the plan.');
        }

        if ($sourceMessage !== null && (
            $sourceMessage->team_id !== $mealPlan->team_id
            || $sourceMessage->conversation?->meal_plan_id !== $mealPlan->id
        )) {
            throw new AuthorizationException('That message does not belong to this meal plan.');
        }

        return DB::transaction(function () use ($mealPlan, $user, $date, $kind, $label, $people, $sourceMessage): MealSlot {
            MealPlan::query()->whereKey($mealPlan)->lockForUpdate()->firstOrFail();

            $idempotencyKey = $sourceMessage === null ? null : hash('sha256', implode('|', [
                'meal-slot', $mealPlan->id, $sourceMessage->id, $date->toDateString(), $kind->value, $label ?? '',
            ]));

            if ($sourceMessage !== null) {
                $existing = $mealPlan->slots()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing->load('participants');
                }
            }

            $position = (int) $mealPlan->slots()
                ->whereDate('date', $date)
                ->where('kind', $kind)
                ->max('position') + 1;

            $values = [
                'team_id' => $mealPlan->team_id,
                'source_message_id' => $sourceMessage?->id,
                'date' => $date,
                'kind' => $kind,
                'label' => $label,
                'position' => $position,
            ];
            $slot = $sourceMessage === null
                ? $mealPlan->slots()->create($values)
                : $mealPlan->slots()->firstOrCreate(['idempotency_key' => $idempotencyKey], $values);

            $slot->participants()->sync($people->mapWithKeys(fn (Person $person) => [
                $person->id => ['servings' => 1],
            ])->all());

            $this->recordRevision->handle($mealPlan, $user, 'Added '.$kind->value.' on '.$date->toDateString().'.', [
                'meal_slot_id' => $slot->id,
                'date' => $date->toDateString(),
                'kind' => $kind->value,
            ]);

            return $slot->load('participants');
        });
    }
}
