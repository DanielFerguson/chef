<?php

namespace App\Actions\Planning;

use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMealSlot
{
    /** @param iterable<Person> $participants */
    public function handle(
        MealPlan $mealPlan,
        User $user,
        CarbonInterface $date,
        MealSlotKind $kind,
        iterable $participants,
        ?string $label = null,
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

        return DB::transaction(function () use ($mealPlan, $date, $kind, $label, $people): MealSlot {
            $position = (int) $mealPlan->slots()
                ->whereDate('date', $date)
                ->where('kind', $kind)
                ->max('position') + 1;

            $slot = $mealPlan->slots()->create([
                'team_id' => $mealPlan->team_id,
                'date' => $date,
                'kind' => $kind,
                'label' => $label,
                'position' => $position,
            ]);

            $slot->participants()->sync($people->mapWithKeys(fn (Person $person) => [
                $person->id => ['servings' => 1],
            ])->all());

            return $slot->load('participants');
        });
    }
}
