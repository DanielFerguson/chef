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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        $conversationContext = $conversation === null
            ? []
            : $this->conversationContext($mealPlan, $conversation->messages()->getQuery());
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

    /**
     * @param  Builder<Message>  $messages
     * @return list<array{message_id: int, role: string, sources: list<string>, content: string}>
     */
    private function conversationContext(MealPlan $mealPlan, $messages): array
    {
        $participantIds = $mealPlan->slots()
            ->with('participants:id')
            ->get()
            ->flatMap(fn ($slot) => $slot->participants->pluck('id'))
            ->unique()
            ->values();
        $proposalSourceIds = $mealPlan->plannedMeals()
            ->with('proposal:id,message_id')
            ->get()
            ->pluck('proposal.message_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);
        $preferenceSourceIds = Preference::query()
            ->where('team_id', $mealPlan->team_id)
            ->whereNull('superseded_at')
            ->where(function ($query) use ($participantIds): void {
                $query->whereNull('person_id')->orWhereIn('person_id', $participantIds);
            })
            ->pluck('source_message_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);
        $constraintSourceIds = Constraint::query()
            ->where('team_id', $mealPlan->team_id)
            ->where(function ($query) use ($participantIds): void {
                $query->whereNull('person_id')->orWhereIn('person_id', $participantIds);
            })
            ->pluck('confirmation_message_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);
        $recentInstructionIds = (clone $messages)
            ->where('role', 'user')
            ->reorder('id', 'desc')
            ->limit(30)
            ->get(['id', 'content'])
            ->filter(fn (Message $message): bool => $this->looksLikePlanInstruction($message->content))
            ->take(12)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $sourcesByMessage = [
            'proposal_source' => $proposalSourceIds,
            'preference_source' => $preferenceSourceIds,
            'constraint_source' => $constraintSourceIds,
            'recent_plan_instruction' => $recentInstructionIds,
        ];
        $messageIds = collect($sourcesByMessage)
            ->flatMap(fn (Collection $ids): Collection => $ids)
            ->unique()
            ->values();

        return array_values((clone $messages)
            ->whereIn('id', $messageIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message): array => [
                'message_id' => $message->id,
                'role' => $message->role->value,
                'sources' => array_values(collect($sourcesByMessage)
                    ->filter(fn (Collection $ids): bool => $ids->contains($message->id))
                    ->keys()
                    ->values()
                    ->all()),
                'content' => $message->content,
            ])
            ->values()
            ->all());
    }

    private function looksLikePlanInstruction(string $content): bool
    {
        return Str::contains(Str::lower($content), [
            'time',
            'minute',
            'quick',
            'budget',
            'cheap',
            'cost',
            'variety',
            'different',
            'repeat',
            'leftover',
            'nutrition',
            'healthy',
            'protein',
            'vegetable',
            'ingredient',
            'avoid',
            'pantry',
            'spicy',
            'serve',
        ]);
    }
}
