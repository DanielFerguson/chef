<?php

namespace App\Actions\Recipes;

use App\Ai\Data\RecipeDraftRequest;
use App\Enums\PlannedMealRecipePreparationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Jobs\MaterializePlannedMealRecipeJob;
use App\Models\PlannedMeal;
use App\Models\PlannedMealRecipePreparation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparePlannedMealRecipe
{
    public function handle(PlannedMeal $plannedMeal, User $user): ?PlannedMealRecipePreparation
    {
        if (! $user->memberships()->where('team_id', $plannedMeal->team_id)->exists()) {
            throw new AuthorizationException('You cannot prepare a recipe for this meal.');
        }

        if ($plannedMeal->status !== PlannedMealStatus::Planned || $plannedMeal->type !== PlannedMealType::Custom) {
            return null;
        }

        if ($plannedMeal->recipe_version_id !== null) {
            return $plannedMeal->recipePreparation;
        }

        $request = $this->buildRequest($plannedMeal);
        $input = $request->jsonSerialize();
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        [$preparation, $shouldDispatch] = DB::transaction(function () use ($plannedMeal, $user, $input, $fingerprint): array {
            $lockedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($plannedMeal->id);

            if ($lockedMeal->recipe_version_id !== null) {
                return [$lockedMeal->recipePreparation, false];
            }

            $preparation = PlannedMealRecipePreparation::query()
                ->where('planned_meal_id', $lockedMeal->id)
                ->lockForUpdate()
                ->first();

            if ($preparation === null) {
                $preparation = PlannedMealRecipePreparation::query()->create([
                    'team_id' => $lockedMeal->team_id,
                    'planned_meal_id' => $lockedMeal->id,
                    'requested_by_user_id' => $user->id,
                    'status' => PlannedMealRecipePreparationStatus::Pending,
                    'input_fingerprint' => $fingerprint,
                    'input' => $input,
                ]);

                return [$preparation, true];
            }

            $unchangedActive = $preparation->input_fingerprint === $fingerprint
                && in_array($preparation->status, [
                    PlannedMealRecipePreparationStatus::Pending,
                    PlannedMealRecipePreparationStatus::Processing,
                ], true);

            if ($unchangedActive) {
                return [$preparation, false];
            }

            $preparation->update([
                'requested_by_user_id' => $user->id,
                'recipe_version_id' => null,
                'status' => PlannedMealRecipePreparationStatus::Pending,
                'input_fingerprint' => $fingerprint,
                'input' => $input,
                'failure_code' => null,
                'failure_message' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);

            return [$preparation, true];
        });

        if ($preparation === null) {
            throw ValidationException::withMessages(['planned_meal' => 'This meal already has a recipe.']);
        }

        if ($shouldDispatch) {
            MaterializePlannedMealRecipeJob::dispatch($preparation->id);
        }

        return $preparation->refresh();
    }

    private function buildRequest(PlannedMeal $plannedMeal): RecipeDraftRequest
    {
        $plannedMeal->loadMissing([
            'mealSlot',
            'mealSlot.participants.preferences',
            'mealSlot.participants.constraints',
            'proposal',
            'mealPlan.team.preferences' => fn ($query) => $query->whereNull('person_id'),
            'mealPlan.team.constraints' => fn ($query) => $query->whereNull('person_id'),
        ]);
        $team = $plannedMeal->mealPlan->team;
        $participants = $plannedMeal->mealSlot->participants;
        $otherMeals = $plannedMeal->mealPlan->plannedMeals()
            ->whereKeyNot($plannedMeal->id)
            ->where('status', PlannedMealStatus::Planned->value)
            ->with('mealSlot:id,date,kind')
            ->get()
            ->map(fn (PlannedMeal $meal) => [
                'title' => $meal->title,
                'date' => $meal->mealSlot->date->toDateString(),
                'kind' => $meal->mealSlot->kind->value,
            ])
            ->sortBy(fn (array $meal) => $meal['date'].'|'.$meal['kind'].'|'.$meal['title'])
            ->values()
            ->all();

        $preferences = $team->preferences->map(fn ($preference) => [
            'owner' => 'household',
            'subject' => $preference->subject,
            'sentiment' => $preference->sentiment->value,
            'provenance' => $preference->provenance->value,
        ])->concat($participants->flatMap(fn ($person) => $person->preferences->map(fn ($preference) => [
            'owner' => $person->name,
            'subject' => $preference->subject,
            'sentiment' => $preference->sentiment->value,
            'provenance' => $preference->provenance->value,
        ])->all()))->sortBy(fn (array $item) => $item['owner'].'|'.$item['subject'])->values()->all();

        $constraints = $team->constraints->map(fn ($constraint) => [
            'owner' => 'household',
            'kind' => $constraint->kind->value,
            'subject' => $constraint->subject,
            'details' => $constraint->details,
            'severity' => $constraint->severity,
        ])->concat($participants->flatMap(fn ($person) => $person->constraints->map(fn ($constraint) => [
            'owner' => $person->name,
            'kind' => $constraint->kind->value,
            'subject' => $constraint->subject,
            'details' => $constraint->details,
            'severity' => $constraint->severity,
        ])->all()))->sortBy(fn (array $item) => $item['owner'].'|'.$item['kind'].'|'.$item['subject'])->values()->all();

        return new RecipeDraftRequest(
            teamId: $team->id,
            mealPlanId: $plannedMeal->meal_plan_id,
            plannedMealId: $plannedMeal->id,
            mealDate: $plannedMeal->mealSlot->date->toDateString(),
            mealKind: $plannedMeal->mealSlot->kind->value,
            proposalId: $plannedMeal->meal_proposal_id,
            sourceMessageId: $plannedMeal->proposal?->message_id,
            householdName: $team->name,
            title: $plannedMeal->title,
            summary: $plannedMeal->summary,
            servings: $plannedMeal->servings,
            estimatedMinutes: $plannedMeal->estimated_minutes,
            preferences: $preferences,
            constraints: $constraints,
            otherMeals: $otherMeals,
        );
    }
}
