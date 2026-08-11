<?php

namespace App\Actions\MealPlans;

use App\Actions\Baskets\ProjectBasketRunPublicState;
use App\Actions\Conversations\ResolvePendingPlanApproval;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerProvider;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\User;

class BuildMealPlanWorkspace
{
    public function __construct(
        private readonly AssessMealPlanReadiness $assessReadiness,
        private readonly BuildMealPlanApprovalBrief $buildApprovalBrief,
        private readonly ProjectBasketRunPublicState $projectBasketPublicState,
        private readonly ResolvePendingPlanApproval $resolvePendingPlanApproval,
    ) {}

    /** @return array<string, mixed> */
    public function handle(MealPlan $mealPlan, User $user): array
    {
        $mealPlan->load([
            'conversations.messages.author:id,name',
            'conversations.messages.attachments',
            'conversations.messages.feedback' => fn ($query) => $query->whereBelongsTo($user),
            'conversations.feedback' => fn ($query) => $query->whereBelongsTo($user)->whereNull('message_id'),
            'slots.participants',
            'slots.plannedMeal.recipeVersion.ingredients',
            'slots.plannedMeal.recipeVersion.steps',
            'slots.plannedMeal.recipeVersion.equipment',
            'slots.plannedMeal.recipeVersion.preparationNotices',
            'slots.plannedMeal.sourcePlannedMeal',
            'proposals' => fn ($query) => $query->latest(),
            'revisions' => fn ($query) => $query->limit(20),
            'milestones',
            'team.people.userLink',
            'team.people.preferences.sourceMessage:id,conversation_id',
            'team.people.constraints.confirmationMessage.author:id,name',
            'team.preferences' => fn ($query) => $query
                ->whereNull('person_id')
                ->with('sourceMessage:id,conversation_id'),
            'team.constraints' => fn ($query) => $query
                ->whereNull('person_id')
                ->with('confirmationMessage.author:id,name'),
            'team.recipes.latestVersion.ingredients',
        ]);

        return [
            'plan' => $mealPlan,
            'conversation' => $mealPlan->conversations->firstOrFail(),
            'household' => $mealPlan->team,
            'recipes' => $mealPlan->team->recipes,
            'readiness' => $this->assessReadiness->handle($mealPlan),
            'approval_brief' => $this->buildApprovalBrief->handle($mealPlan, $user),
            'pending_tool_approval' => $this->resolvePendingPlanApproval->handle(
                $mealPlan->conversations->firstOrFail(),
            ),
            'grocery_preparation' => $this->groceryPreparation($mealPlan, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function groceryPreparation(MealPlan $mealPlan, User $user): array
    {
        if (! config('retailer.features.experience', false)) {
            return ['enabled' => false];
        }

        $connection = RetailerConnection::query()
            ->where('team_id', $mealPlan->team_id)
            ->where('provider', RetailerProvider::Coles)
            ->with(['grants' => fn ($query) => $query
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')])
            ->first();
        $run = $mealPlan->basketRuns()->latest('id')->first();
        $hasStandingConsent = $connection?->grants->isNotEmpty() === true;
        $public = $run === null ? null : $this->projectBasketPublicState->handle($run);

        return [
            'enabled' => true,
            'provider' => RetailerProvider::Coles->value,
            'approval_label' => $hasStandingConsent
                ? 'Approve plan & prepare Coles basket'
                : 'Approve plan & prepare recipes',
            'has_standing_consent' => $hasStandingConsent,
            'connection' => $connection === null ? null : [
                'id' => $connection->id,
                'status' => $connection->status->value,
                'owned_by_current_user' => $connection->owner_user_id === $user->id,
            ],
            'can_connect' => $connection === null || $connection->owner_user_id === $user->id,
            'run' => $run === null ? null : [
                'id' => $run->id,
                'status' => $run->status->value,
                'public_state' => $public['state'],
                'public_outcome' => $public['outcome'],
                'attention_kind' => $run->attention_kind,
                'failure_message' => $run->failure_message,
                'chef_subtotal_cents' => $run->chef_subtotal_cents,
                'retailer_total_cents' => $run->retailer_total_cents,
                'captured_at' => $run->basket_captured_at?->toIso8601String(),
                'polling' => $public['state'] === 'preparing',
            ],
            'consent' => [
                'version' => config('retailer.consent.disclosure_version'),
                'disclosure' => config('retailer.consent.disclosure'),
                'links' => config('retailer.consent.links'),
            ],
        ];
    }
}
