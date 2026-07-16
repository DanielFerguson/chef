<?php

namespace App\Ai\Agents;

use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\MovePlannedMeal;
use App\Actions\Planning\ProposeMeal;
use App\Ai\Tools\CreateMealProposal;
use App\Ai\Tools\CreatePlanMealSlot;
use App\Ai\Tools\InspectMealPlan;
use App\Ai\Tools\InspectTeamContext;
use App\Ai\Tools\MoveSelectedMeal;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Ai\Tools\RecordSafetyConstraint;
use App\Ai\Tools\UpdatePlanDateSpan;
use App\Models\Conversation;
use App\Models\Message as ChefMessage;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class ChefAgent implements Agent, Conversational, HasTools
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
            new InspectMealPlan($mealPlan),
            new UpdatePlanDateSpan($mealPlan, $this->actor, app(UpdateMealPlanDateSpan::class)),
            new CreatePlanMealSlot($mealPlan, $this->actor, app(CreateMealSlot::class)),
            new CreateMealProposal($mealPlan, $this->actor, $this->currentMessage, app(ProposeMeal::class)),
            new MoveSelectedMeal($mealPlan, $this->actor, app(MovePlannedMeal::class)),
            new RecordHouseholdPreference($team, $this->actor, app(RecordPreference::class)),
            new RecordSafetyConstraint($team, $this->actor, app(RecordConstraint::class)),
        ];
    }
}
