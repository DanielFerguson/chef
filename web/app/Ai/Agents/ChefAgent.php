<?php

namespace App\Ai\Agents;

use App\Actions\Households\CorrectPreference;
use App\Actions\Households\CreateHouseholdPerson as CreateHouseholdPersonAction;
use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\Households\ValidatePreferenceEvidence;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\MovePlannedMeal;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\AddShoppingListItems;
use App\Actions\Shopping\PrepareMealPlanShoppingList;
use App\Actions\Shopping\SetShoppingBudget;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Ai\Tools\AddPlanShoppingItems;
use App\Ai\Tools\ConfirmPlan;
use App\Ai\Tools\CorrectHouseholdPreference;
use App\Ai\Tools\CreateFamilyRecipe;
use App\Ai\Tools\CreateHouseholdPerson;
use App\Ai\Tools\CreateMealProposal;
use App\Ai\Tools\CreatePlanMealSlot;
use App\Ai\Tools\InspectMealPlan;
use App\Ai\Tools\InspectPlanShoppingList;
use App\Ai\Tools\InspectRecipes;
use App\Ai\Tools\InspectTeamContext;
use App\Ai\Tools\MoveSelectedMeal;
use App\Ai\Tools\PreparePlanShoppingList;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Ai\Tools\RecordSafetyConstraint;
use App\Ai\Tools\SelectPlanMeal;
use App\Ai\Tools\SetPlanShoppingBudget;
use App\Ai\Tools\UpdatePlanDateSpan;
use App\Ai\Tools\UpdatePlanShoppingItem;
use App\Models\Conversation;
use App\Models\Message as ChefMessage;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class ChefAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(
        private readonly Conversation $conversation,
        private readonly int $beforeMessageId,
        private readonly User $actor,
        private readonly ChefMessage $currentMessage,
    ) {}

    public function instructions(): Stringable|string
    {
        $team = $this->conversation->team()
            ->with(['people.preferences', 'people.constraints', 'preferences', 'constraints'])
            ->firstOrFail();

        $people = $team->people->map(fn ($person) => $person->name)->join(', ');
        $householdKnowledge = json_encode([
            'team_preferences' => $team->preferences->map(fn ($preference) => [
                'subject' => $preference->subject,
                'sentiment' => $preference->sentiment->value,
                'provenance' => $preference->provenance->value,
            ])->all(),
            'team_constraints' => $team->constraints->map(fn ($constraint) => [
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'details' => $constraint->details,
            ])->all(),
            'people' => $team->people->map(fn ($person) => [
                'name' => $person->name,
                'preferences' => $person->preferences->map(fn ($preference) => [
                    'subject' => $preference->subject,
                    'sentiment' => $preference->sentiment->value,
                    'provenance' => $preference->provenance->value,
                ])->all(),
                'constraints' => $person->constraints->map(fn ($constraint) => [
                    'kind' => $constraint->kind->value,
                    'subject' => $constraint->subject,
                    'details' => $constraint->details,
                ])->all(),
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        You are Chef, a warm and practical meal-planning partner for {$team->name}.
        The household timezone is {$team->timezone}. The people currently known are: {$people}.

        Help the household create a meal plan through natural conversation. Ask only the next useful
        question, explain suggestions briefly, and keep plans realistic for the stated dates, people,
        budget, time, cooking ability, and retailer. Treat Chef's structured records as the source of
        truth. Never infer an allergy or medical restriction: only treat one as a safety constraint
        after a person explicitly confirms it. Keep stated preferences distinct from inferred tastes.
        Keep provisional defaults visibly labelled as defaults so the household can correct them.

        Household truth rules:
        - Before saying a stated preference was saved or noted, call RecordHouseholdPreference successfully.
        - Use an exact evidence quote from the current user message. Never replay a preference from an unrelated later turn.
        - Assign a preference to a person only when their name is present or a pronoun has one unambiguous recent referent. Otherwise ask one clarifying question.
        - When the user says an existing preference belongs to someone else, inspect the current records and call CorrectHouseholdPreference. Do not leave both records active.
        - A correction is not a new inference. Preserve its human sources and acknowledge only the correction that actually succeeded.

        Planning momentum rules:
        - Treat plan_progress returned by planning tools as authoritative. Do not reconstruct the selected plan from prose.
        - After each selection, state what changed and immediately guide the household to the reported next_action.
        - When ready_for_confirmation is true, say that every slot is filled and ask one direct question: whether to review and confirm the plan. Explain that shopping follows confirmation.
        - Filling the final slot is not consent to confirm. Call ConfirmPlan only after the user explicitly agrees.
        - After confirmation, acknowledge it and offer to begin the shopping-list step. Never end a completed planning turn with only “the week is fully filled”.

        Recipe and shopping rules:
        - A selected ordinary cookable meal needs a prepared recipe. Recipe preparation happens automatically; report plan_progress instead of asking the household to type ingredients.
        - If recipes are still preparing, say so clearly and let the household continue. If preparation failed, offer a retry rather than silently omitting the meal.
        - When the household asks to begin shopping, call PreparePlanShoppingList. Do not merely describe a possible list in prose.
        - Inspect the shopping list before editing it. Use exact item identifiers and the current list revision.
        - When one message requests multiple household extras, call AddPlanShoppingItems once with every requested item. Never split one addition request into repeated single-item writes.
        - Pantry, quantity, inclusion, check-off, household-extra, and budget requests must update structured list state through their tools before you say they are done.
        - Budget is optional. Guide the household to review generated ingredients and pantry state before implying that a budget is required.
        - Keep the conversation moving by stating what changed and offering the next useful shopping decision.

        Current structured household knowledge:
        {$householdKnowledge}
        INSTRUCTIONS;
    }

    /** @return Message[] */
    public function messages(): iterable
    {
        return $this->conversation->messages()
            ->where('id', '<', $this->beforeMessageId)
            ->get()
            ->map(fn (ChefMessage $message) => new Message($message->role->value, $message->content))
            ->all();
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        return $provider === Lab::OpenAI
            ? ['parallel_tool_calls' => false]
            : [];
    }

    /** @return Tool[] */
    public function tools(): iterable
    {
        $team = $this->conversation->team;
        $mealPlan = $this->conversation->mealPlan;

        if ($mealPlan === null) {
            return [];
        }

        return [
            new InspectTeamContext($team),
            new InspectMealPlan($mealPlan, app(AssessMealPlanReadiness::class)),
            new InspectPlanShoppingList($mealPlan, app(AssessMealPlanReadiness::class)),
            new InspectRecipes($team),
            new CreateHouseholdPerson($team, $this->actor, $this->currentMessage, app(CreateHouseholdPersonAction::class)),
            new UpdatePlanDateSpan($mealPlan, $this->actor, app(UpdateMealPlanDateSpan::class)),
            new CreatePlanMealSlot($mealPlan, $this->actor, $this->currentMessage, app(CreateMealSlot::class)),
            new CreateMealProposal($mealPlan, $this->actor, $this->currentMessage, app(ProposeMeal::class)),
            new CreateFamilyRecipe($team, $this->actor, $this->currentMessage, app(CreateRecipe::class)),
            new SelectPlanMeal($mealPlan, $this->actor, app(SelectPlannedMeal::class), app(AssessMealPlanReadiness::class)),
            new PreparePlanShoppingList($mealPlan, $this->actor, app(PrepareMealPlanShoppingList::class), app(AssessMealPlanReadiness::class)),
            new AddPlanShoppingItems($mealPlan, $this->actor, $this->currentMessage, app(AddShoppingListItems::class)),
            new UpdatePlanShoppingItem($mealPlan, $this->actor, app(UpdateShoppingListItem::class)),
            new SetPlanShoppingBudget($mealPlan, $this->actor, app(SetShoppingBudget::class)),
            new MoveSelectedMeal($mealPlan, $this->actor, app(MovePlannedMeal::class)),
            new RecordHouseholdPreference($team, $this->actor, $this->currentMessage, app(RecordPreference::class), app(ValidatePreferenceEvidence::class)),
            new CorrectHouseholdPreference($team, $this->actor, $this->currentMessage, app(CorrectPreference::class)),
            new ConfirmPlan($mealPlan, $this->actor, app(ConfirmMealPlan::class), app(AssessMealPlanReadiness::class)),
            new RecordSafetyConstraint($team, $this->actor, $this->currentMessage, app(RecordConstraint::class)),
        ];
    }
}
