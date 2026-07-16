<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Models\MealSlot;
use App\Models\Person;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMealSlotParticipants
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    /** @param array<int, float|int> $servingsByPerson */
    public function handle(MealSlot $slot, User $user, array $servingsByPerson, ?int $expectedRevision = null): MealSlot
    {
        if (! $user->memberships()->where('team_id', $slot->team_id)->exists()) {
            throw new AuthorizationException('You cannot update participants for this meal.');
        }

        $people = Person::query()->where('team_id', $slot->team_id)->whereIn('id', array_keys($servingsByPerson))->get();

        if ($people->count() !== count($servingsByPerson) || collect($servingsByPerson)->contains(fn ($servings) => (float) $servings <= 0)) {
            throw ValidationException::withMessages(['participants' => 'Choose valid household members with servings above zero.']);
        }

        return DB::transaction(function () use ($slot, $user, $people, $servingsByPerson, $expectedRevision): MealSlot {
            $slot->participants()->sync($people->mapWithKeys(fn (Person $person) => [
                $person->id => ['servings' => (float) $servingsByPerson[$person->id]],
            ])->all());
            $this->recordRevision->handle($slot->mealPlan, $user, 'Updated participants for '.$slot->date->toDateString().'.', [
                'meal_slot_id' => $slot->id,
                'participants' => $servingsByPerson,
            ], $expectedRevision);

            return $slot->load('participants');
        });
    }
}
