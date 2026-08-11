<?php

namespace App\Actions\Planning;

use App\Enums\MealSlotKind;
use App\Enums\MealSlotParticipantOrigin;
use App\Models\MealPlan;
use App\Models\MealSlot;
use Carbon\CarbonInterface;

class ResolveMealSlotParticipants
{
    /**
     * @param  array<int, float|int>|null  $explicitServingsByPerson
     * @return array{
     *     servings_by_person: array<int, float>,
     *     origin: MealSlotParticipantOrigin,
     *     source_meal_slot_id: int|null
     * }
     */
    public function handle(
        MealPlan $mealPlan,
        CarbonInterface $date,
        MealSlotKind $kind,
        ?array $explicitServingsByPerson = null,
    ): array {
        if ($explicitServingsByPerson !== null && $explicitServingsByPerson !== []) {
            return [
                'servings_by_person' => array_map(
                    static fn (float|int $servings): float => (float) $servings,
                    $explicitServingsByPerson,
                ),
                'origin' => MealSlotParticipantOrigin::Explicit,
                'source_meal_slot_id' => null,
            ];
        }

        $currentPeopleById = $mealPlan->team->people()->pluck('id')->flip();
        $historicalSlot = MealSlot::query()
            ->where('team_id', $mealPlan->team_id)
            ->where('kind', $kind)
            ->whereDate('date', '<', $date)
            ->whereHas('mealPlan', fn ($query) => $query->whereNotNull('planning_confirmed_at'))
            ->whereHas('plannedMeal')
            ->with('participants:id')
            ->latest('date')
            ->latest('id')
            ->limit(52)
            ->get()
            ->first(fn (MealSlot $slot): bool => $slot->date->dayOfWeekIso === $date->dayOfWeekIso);

        if ($historicalSlot !== null) {
            $historicalServings = $historicalSlot->participants
                ->filter(fn ($person): bool => $currentPeopleById->has($person->id))
                ->mapWithKeys(fn ($person): array => [
                    $person->id => (float) $person->getRelation('pivot')->getAttribute('servings'),
                ])
                ->all();

            if ($historicalServings !== []) {
                return [
                    'servings_by_person' => $historicalServings,
                    'origin' => MealSlotParticipantOrigin::ProvisionalHistory,
                    'source_meal_slot_id' => $historicalSlot->id,
                ];
            }
        }

        return [
            'servings_by_person' => $mealPlan->team->people()
                ->orderBy('id')
                ->pluck('id')
                ->mapWithKeys(fn (int $personId): array => [$personId => 1.0])
                ->all(),
            'origin' => MealSlotParticipantOrigin::FallbackHousehold,
            'source_meal_slot_id' => null,
        ];
    }
}
