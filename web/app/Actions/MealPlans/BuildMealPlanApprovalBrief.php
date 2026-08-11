<?php

namespace App\Actions\MealPlans;

use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Enums\MealProposalStatus;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerProvider;
use App\Models\Constraint;
use App\Models\MealPlan;
use App\Models\MealProposal;
use App\Models\MealSlot;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class BuildMealPlanApprovalBrief
{
    public function __construct(
        private readonly ResolveEffectiveRetailerPurchasePolicy $resolvePurchasePolicy,
    ) {}

    /** @return array<string, mixed> */
    public function handle(MealPlan $mealPlan, User $user): array
    {
        if (! $user->can('view', $mealPlan)) {
            throw new AuthorizationException('You cannot review this meal plan.');
        }

        $mealPlan->loadMissing([
            'slots.participants',
            'slots.participantSourceSlot',
            'slots.plannedMeal',
            'proposals',
        ]);
        $pendingBySlot = $mealPlan->proposals
            ->where('status', MealProposalStatus::Pending)
            ->whereNotNull('meal_slot_id')
            ->groupBy('meal_slot_id');

        $meals = $mealPlan->slots
            ->sortBy([['date', 'asc'], ['position', 'asc']])
            ->map(function (MealSlot $slot) use ($pendingBySlot): array {
                $pending = $pendingBySlot->get($slot->id, collect());
                $proposal = $pending->count() === 1 ? $pending->first() : null;
                $effective = $proposal ?? $slot->plannedMeal;

                return [
                    'meal_slot_id' => $slot->id,
                    'date' => $slot->date->toDateString(),
                    'kind' => $slot->kind->value,
                    'label' => $slot->label,
                    'title' => $effective?->title,
                    'summary' => $effective?->summary,
                    'estimated_minutes' => $effective?->estimated_minutes,
                    'estimated_cost_cents' => $effective?->estimated_cost === null
                        ? null
                        : (int) round($effective->estimated_cost * 100),
                    'is_replacement' => $proposal instanceof MealProposal && $slot->plannedMeal !== null,
                    'has_conflicting_proposals' => $pending->count() > 1,
                    'participants' => $slot->participants
                        ->sortBy('id')
                        ->map(fn ($person): array => [
                            'person_id' => $person->id,
                            'name' => $person->name,
                            'servings' => (float) $person->getRelation('pivot')->getAttribute('servings'),
                        ])
                        ->values()
                        ->all(),
                    'total_servings' => (float) $slot->participants->sum(
                        fn ($person): float => (float) $person->getRelation('pivot')->getAttribute('servings'),
                    ),
                    'participant_default' => [
                        'origin' => $slot->participant_assignment_origin->value,
                        'provisional' => $slot->participant_assignment_origin->value !== 'explicit',
                        'source_meal_slot_id' => $slot->participant_source_meal_slot_id,
                        'source_label' => $slot->participantSourceSlot === null
                            ? null
                            : $slot->participantSourceSlot->date->toDateString().' '.$slot->participantSourceSlot->kind->value,
                    ],
                ];
            })
            ->values();

        $constraints = Constraint::query()
            ->where('team_id', $mealPlan->team_id)
            ->with('person')
            ->get()
            ->sortBy('id')
            ->map(fn (Constraint $constraint): array => [
                'id' => $constraint->id,
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'details' => $constraint->details,
                'severity' => $constraint->severity,
                'person' => $constraint->person?->name,
                'explicitly_confirmed_at' => $constraint->explicitly_confirmed_at->toIso8601String(),
            ])
            ->values()
            ->all();
        $hasStandingConsent = RetailerConnection::query()
            ->where('team_id', $mealPlan->team_id)
            ->where('provider', RetailerProvider::Coles)
            ->whereHas('grants', fn ($query) => $query
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at'))
            ->exists();
        $effectivePolicy = $this->resolvePurchasePolicy->handle($mealPlan);
        $policy = $effectivePolicy['snapshot'];

        return [
            'plan_id' => $mealPlan->id,
            'plan_revision' => $mealPlan->revision,
            'meal_count' => $meals->count(),
            'meals' => $meals->all(),
            'estimated_minutes' => $meals->sum(fn (array $meal): int => (int) ($meal['estimated_minutes'] ?? 0)),
            'estimated_cost_cents' => $meals->sum(fn (array $meal): int => (int) ($meal['estimated_cost_cents'] ?? 0)),
            'safety' => [
                'constraints' => $constraints,
                'inferred' => false,
            ],
            'purchase_policy' => [
                'provider' => RetailerProvider::Coles->value,
                'home_brand_preference' => $policy['home_brand_preference'],
                'bulk_preference' => $policy['bulk_preference'],
                'organic_preference' => $policy['organic_preference'],
                'preferred_brands' => $policy['preferred_brands'],
                'basket_target_cents' => $effectivePolicy['basket_target_cents'],
                'basket_target_source' => $policy['effective_basket_target_source'],
            ],
            'grocery_preparation' => [
                'provider' => RetailerProvider::Coles->value,
                'has_standing_consent' => $hasStandingConsent,
                'approval_will_replace_basket' => $hasStandingConsent,
                'effect' => $hasStandingConsent
                    ? 'Approval starts recipes and preparation of a replacement Coles basket.'
                    : 'Approval starts recipes. Chef will ask the Coles account owner to connect before preparing the basket.',
            ],
        ];
    }
}
