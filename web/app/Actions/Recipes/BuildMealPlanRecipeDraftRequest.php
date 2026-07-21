<?php

namespace App\Actions\Recipes;

use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Ai\Data\RecipeDraftRequest;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Models\Constraint;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\Person;
use App\Models\PlannedMeal;
use App\Models\Preference;

class BuildMealPlanRecipeDraftRequest
{
    public function handle(MealPlan $mealPlan): MealPlanRecipeDraftRequest
    {
        $mealPlan->loadMissing([
            'team.preferences' => fn ($query) => $query->whereNull('person_id'),
            'team.constraints' => fn ($query) => $query->whereNull('person_id'),
        ]);
        $team = $mealPlan->team;
        $conversation = $mealPlan->conversations()->latest('id')->first();
        $conversationContext = $conversation?->messages()
            ->reorder('id', 'desc')
            ->limit(40)
            ->get()
            ->reverse()
            ->map(fn (Message $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->values()
            ->all() ?? [];
        $otherMeals = $mealPlan->plannedMeals()
            ->where('status', PlannedMealStatus::Planned->value)
            ->with('mealSlot:id,date,kind')
            ->get()
            ->map(fn (PlannedMeal $meal): array => [
                'title' => $meal->title,
                'date' => $meal->mealSlot->date->toDateString(),
                'kind' => $meal->mealSlot->kind->value,
            ])
            ->sortBy(fn (array $meal): string => $meal['date'].'|'.$meal['kind'].'|'.$meal['title'])
            ->values()
            ->all();

        $meals = $mealPlan->plannedMeals()
            ->where('status', PlannedMealStatus::Planned->value)
            ->where('type', PlannedMealType::Custom->value)
            ->whereNull('recipe_version_id')
            ->with([
                'mealSlot.participants.preferences',
                'mealSlot.participants.constraints',
                'proposal',
            ])
            ->get()
            ->sortBy(fn (PlannedMeal $meal): string => $meal->mealSlot->date->toDateString().'|'.$meal->mealSlot->position.'|'.$meal->id)
            ->map(function (PlannedMeal $meal) use ($team, $otherMeals): array {
                $participants = $meal->mealSlot->participants;
                $preferences = $team->preferences->map(fn (Preference $preference): array => [
                    'owner' => 'household',
                    'subject' => $preference->subject,
                    'sentiment' => $preference->sentiment->value,
                    'provenance' => $preference->provenance->value,
                ])->concat($participants->flatMap(fn (Person $person) => $person->preferences->map(fn (Preference $preference): array => [
                    'owner' => $person->name,
                    'subject' => $preference->subject,
                    'sentiment' => $preference->sentiment->value,
                    'provenance' => $preference->provenance->value,
                ])->all()))->sortBy(fn (array $item): string => $item['owner'].'|'.$item['subject'])->values()->all();
                $constraints = $team->constraints->map(fn (Constraint $constraint): array => [
                    'owner' => 'household',
                    'kind' => $constraint->kind->value,
                    'subject' => $constraint->subject,
                    'details' => $constraint->details,
                    'severity' => $constraint->severity,
                ])->concat($participants->flatMap(fn (Person $person) => $person->constraints->map(fn (Constraint $constraint): array => [
                    'owner' => $person->name,
                    'kind' => $constraint->kind->value,
                    'subject' => $constraint->subject,
                    'details' => $constraint->details,
                    'severity' => $constraint->severity,
                ])->all()))->sortBy(fn (array $item): string => $item['owner'].'|'.$item['kind'].'|'.$item['subject'])->values()->all();

                return (new RecipeDraftRequest(
                    teamId: $team->id,
                    mealPlanId: $meal->meal_plan_id,
                    plannedMealId: $meal->id,
                    mealDate: $meal->mealSlot->date->toDateString(),
                    mealKind: $meal->mealSlot->kind->value,
                    proposalId: $meal->meal_proposal_id,
                    sourceMessageId: $meal->proposal?->message_id,
                    householdName: $team->name,
                    title: $meal->title,
                    summary: $meal->summary,
                    servings: $meal->servings,
                    estimatedMinutes: $meal->estimated_minutes,
                    preferences: $preferences,
                    constraints: $constraints,
                    otherMeals: array_values(array_filter(
                        $otherMeals,
                        fn (array $other): bool => ! ($other['date'] === $meal->mealSlot->date->toDateString()
                            && $other['kind'] === $meal->mealSlot->kind->value
                            && $other['title'] === $meal->title),
                    )),
                ))->jsonSerialize();
            })
            ->values()
            ->all();

        return new MealPlanRecipeDraftRequest(
            teamId: $team->id,
            mealPlanId: $mealPlan->id,
            householdName: $team->name,
            meals: $meals,
            conversationContext: $conversationContext,
        );
    }

    public function fingerprint(MealPlanRecipeDraftRequest $request): string
    {
        $structuralInput = $request->jsonSerialize();
        unset($structuralInput['conversation_context']);

        return hash('sha256', json_encode($structuralInput, JSON_THROW_ON_ERROR));
    }
}
