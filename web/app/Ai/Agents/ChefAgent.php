<?php

namespace App\Ai\Agents;

use App\Actions\Households\CorrectPreference;
use App\Actions\Households\CreateHouseholdPerson as CreateHouseholdPersonAction;
use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\Households\ValidatePreferenceEvidence;
use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\BuildMealPlanApprovalBrief;
use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\MovePlannedMeal;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\ResolveMealSlotParticipants;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Retailers\RememberRetailerProductPreference;
use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Actions\Retailers\ValidateExplicitRetailerPreferenceEvidence;
use App\Ai\Tools\ConfirmPlan;
use App\Ai\Tools\CorrectHouseholdPreference;
use App\Ai\Tools\CreateFamilyRecipe;
use App\Ai\Tools\CreateHouseholdPerson;
use App\Ai\Tools\CreateMealProposal;
use App\Ai\Tools\CreatePlanMealSlot;
use App\Ai\Tools\InspectMealPlan;
use App\Ai\Tools\InspectRecipes;
use App\Ai\Tools\InspectTeamContext;
use App\Ai\Tools\MoveSelectedMeal;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Ai\Tools\RecordSafetyConstraint;
use App\Ai\Tools\RecoverableApprovableTool;
use App\Ai\Tools\RecoverableTool;
use App\Ai\Tools\SaveRetailerProductPreference;
use App\Ai\Tools\SaveRetailerPurchasePolicy;
use App\Ai\Tools\SelectPlanMeal;
use App\Ai\Tools\UpdatePlanDateSpan;
use App\Models\Conversation;
use App\Models\Message as ChefMessage;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

class ChefAgent implements Agent, Conversational, HasProviderOptions, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations {
        messages as rememberedMessages;
    }

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
        time and cooking ability. Treat Chef's structured records as the source of
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
        - Before creating, selecting, or moving meal options, call InspectMealPlan and use its current slots, selected meals, pending proposals, and plan_progress.
        - Meals named in response to a question about this week or the current plan are plan-specific options. Create one reviewable proposal per named meal; do not save them as household preferences unless the person explicitly asks Chef to remember them beyond this plan.
        - When asked to plan or suggest the remaining meals, create one distinct proposal for every uncovered slot in chronological order in the same turn. Do not ask whether you should suggest the rest, tidy proposal state, show the completed week, or check back after background work.
        - Treat a complete set of pending proposals as one visible draft week. A pending proposal is not yet approved, but it may be described as part of the draft as long as that status is clear.
        - When asked to swap or replace a draft meal on a named day or slot, call CreateMealProposal for that slot. Do not claim a meal was replaced until that tool succeeds; one successful slot-bound proposal supersedes the prior pending draft for that slot.
        - Treat plan_progress returned by planning tools as authoritative. Do not reconstruct the selected plan from prose.
        - Treat plan_progress.blockers as the complete deterministic readiness diagnosis. When more than one blocker needs the household, ask one consolidated clarification that covers all of them; do not make a separate analysis or summarisation call.
        - After a safe planning change, state what changed and keep moving toward one coherent draft rather than asking permission for the obvious next planning action.
        - When ready_for_approval is true, use the visible approval brief as the authoritative summary and call ConfirmPlan with the exact current plan revision to request human approval. Explain the participant defaults and whether approval starts recipe preparation alone or also Coles basket preparation.
        - Requesting ConfirmPlan is not consent and does not approve the plan. Never claim approval until the resumed tool result says approved.
        - After the approved tool result, acknowledge that recipe preparation has started.

        Recipe rules:
        - A selected ordinary cookable meal needs a prepared recipe. Recipe preparation happens automatically; report plan_progress instead of asking the household to type ingredients.
        - If recipes are still preparing, say so clearly and let the household continue. If preparation failed, offer a retry rather than silently omitting the meal.
        - Keep the conversation moving by stating what changed and offering the next useful planning or recipe decision.

        Grocery preference rules:
        - Save a household purchasing policy or a specific product only when the current user explicitly says always, prefer, or next time, and pass an exact evidence quote from that message.
        - Never learn a durable preference from one accepted basket, a routine approval, or activity inside the private Coles review session.
        - Saving a product preference affects later grocery plans only. It must never change the current Coles basket.

        Photo safety rules:
        - Use visual observations only to inform suggestions, and qualify any ingredient you cannot identify confidently.
        - Never infer or persist allergies, safety constraints, medical restrictions, or durable household preferences from a photo alone. Require explicit written confirmation before treating any of these as household truth.

        Current structured household knowledge:
        {$householdKnowledge}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.conversation.model', 'gpt-5.6-luna');
    }

    public function provider(): Lab
    {
        return Lab::OpenAI;
    }

    /** @return Message[] */
    public function messages(): iterable
    {
        if ($this->currentConversation() === null) {
            return $this->conversation->messages()
                ->where('id', '<', $this->beforeMessageId)
                ->with('attachments')
                ->get()
                ->map(fn (ChefMessage $message) => $this->toAiMessage($message))
                ->all();
        }

        $legacyMessages = $this->conversation->ai_context_cutoff_message_id === null
            ? collect()
            : $this->conversation->messages()
                ->where('id', '<=', $this->conversation->ai_context_cutoff_message_id)
                ->with('attachments')
                ->get()
                ->map(fn (ChefMessage $message) => $this->toAiMessage($message));

        return [
            ...$legacyMessages->all(),
            ...collect($this->rememberedMessages())->all(),
        ];
    }

    private function toAiMessage(ChefMessage $message): Message
    {
        if ($message->role->value !== 'user') {
            return new Message($message->role->value, $message->content);
        }

        $attachments = $message->attachments->map(
            fn ($attachment) => Image::fromStorage($attachment->path, $attachment->disk)
                ->withMimeType($attachment->mime_type),
        );

        return new UserMessage(
            trim($message->content) === ''
                ? 'The user shared these photos for meal-planning context.'
                : $message->content,
            $attachments,
        );
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

        $tools = [
            new InspectTeamContext($team),
            new InspectMealPlan($mealPlan, app(AssessMealPlanReadiness::class)),
            new InspectRecipes($team),
            new CreateHouseholdPerson($team, $this->actor, $this->currentMessage, app(CreateHouseholdPersonAction::class)),
            new UpdatePlanDateSpan($mealPlan, $this->actor, app(UpdateMealPlanDateSpan::class)),
            new CreatePlanMealSlot(
                $mealPlan,
                $this->actor,
                $this->currentMessage,
                app(CreateMealSlot::class),
                app(ResolveMealSlotParticipants::class),
            ),
            new CreateMealProposal($mealPlan, $this->actor, $this->currentMessage, app(ProposeMeal::class)),
            new CreateFamilyRecipe($team, $this->actor, $this->currentMessage, app(CreateRecipe::class)),
            new SelectPlanMeal($mealPlan, $this->actor, app(SelectPlannedMeal::class), app(AssessMealPlanReadiness::class)),
            new MoveSelectedMeal($mealPlan, $this->actor, app(MovePlannedMeal::class)),
            new RecordHouseholdPreference($team, $this->actor, $this->currentMessage, app(RecordPreference::class), app(ValidatePreferenceEvidence::class)),
            new SaveRetailerPurchasePolicy(
                $team,
                $this->actor,
                $this->currentMessage,
                app(UpdateRetailerPurchasePolicy::class),
                app(ResolveEffectiveRetailerPurchasePolicy::class),
                app(ValidateExplicitRetailerPreferenceEvidence::class),
            ),
            new SaveRetailerProductPreference(
                $mealPlan,
                $this->actor,
                $this->currentMessage,
                app(RememberRetailerProductPreference::class),
                app(ValidateExplicitRetailerPreferenceEvidence::class),
            ),
            new CorrectHouseholdPreference($team, $this->actor, $this->currentMessage, app(CorrectPreference::class)),
            new ConfirmPlan(
                $mealPlan,
                $this->actor,
                app(ApproveMealPlan::class),
                app(AssessMealPlanReadiness::class),
                app(BuildMealPlanApprovalBrief::class),
            ),
            new RecordSafetyConstraint($team, $this->actor, $this->currentMessage, app(RecordConstraint::class)),
        ];

        return array_map(fn (Tool $tool): Tool => $tool instanceof Approvable
            ? new RecoverableApprovableTool($tool)
            : new RecoverableTool($tool), $tools);
    }
}
