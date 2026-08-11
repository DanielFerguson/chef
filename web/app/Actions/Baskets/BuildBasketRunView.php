<?php

namespace App\Actions\Baskets;

use App\Actions\Retailers\ChooseBalancedPack;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\RetailerCandidateStatus;
use App\Models\BasketRun;
use App\Models\BasketRunItem;
use App\Models\BasketSnapshot;
use App\Models\GroceryPlan;
use App\Models\User;

class BuildBasketRunView
{
    public function __construct(
        private readonly ChooseBalancedPack $chooseBalancedPack,
        private readonly ProjectBasketRunPublicState $projectPublicState,
    ) {}

    /** @return array<string, mixed> */
    public function handle(BasketRun $basketRun, User $user): array
    {
        $basketRun->load([
            'mealPlan:id,team_id,title,starts_on,ends_on',
            'connection:id,team_id,owner_user_id,provider,status,last_verified_at',
            'groceryPlan:id,team_id,meal_plan_id,version,status,purchase_policy_snapshot,purchase_policy_fingerprint,effective_basket_target_cents,built_at',
            'groceryPlan.requirements:id,grocery_plan_id,status,display_name,quantity,unit,quantity_unknown',
            'items.requirement.sources.plannedMeal:id,title',
            'items.requirement.sources.recipeVersion:id,title,version',
            'items.requirement.candidates',
            'items.selection.candidate',
            'adjustmentDrafts' => fn ($query) => $query->latest('id')->limit(1),
            'adjustmentDrafts.items.mealSlot:id,date,kind,label',
            'adjustmentDrafts.items.plannedMeal:id,title',
            'adjustmentDrafts.items.mealProposal:id,title,status',
            'snapshots' => fn ($query) => $query
                ->whereIn('kind', [
                    BasketSnapshotKind::Baseline->value,
                    BasketSnapshotKind::Final->value,
                    BasketSnapshotKind::Restoration->value,
                ])
                ->with('lines')
                ->orderBy('id'),
        ]);
        $baseline = $basketRun->snapshots->firstWhere('kind', BasketSnapshotKind::Baseline);
        $previousLineCount = $baseline instanceof BasketSnapshot
            ? $baseline->line_count
            : 0;
        $public = $this->projectPublicState->handle($basketRun);
        $adjustment = $basketRun->adjustmentDrafts->first();
        $groceryPlanRelation = $basketRun->getRelation('groceryPlan');
        $groceryPlan = $groceryPlanRelation instanceof GroceryPlan ? $groceryPlanRelation : null;
        $policy = $groceryPlan->purchase_policy_snapshot ?? [];
        $attentionDetails = $basketRun->attention_details ?? [];

        return [
            'id' => $basketRun->id,
            'status' => $basketRun->status->value,
            'status_label' => $this->statusLabel($basketRun->status),
            'public_state' => $public['state'],
            'public_outcome' => $public['outcome'],
            'confirmed' => $basketRun->status === BasketRunStatus::Ready,
            'uncertain' => in_array($basketRun->status, [
                BasketRunStatus::Uncertain,
                BasketRunStatus::NeedsAttention,
            ], true),
            'failure_code' => $basketRun->failure_code,
            'failure_message' => $basketRun->failure_message,
            'attention' => $basketRun->attention_kind === null ? null : [
                'kind' => $basketRun->attention_kind,
                'budget_target_cents' => is_numeric($attentionDetails['budget_target_cents'] ?? null)
                    ? (int) $attentionDetails['budget_target_cents']
                    : null,
                'selected_subtotal_cents' => is_numeric($attentionDetails['selected_subtotal_cents'] ?? null)
                    ? (int) $attentionDetails['selected_subtotal_cents']
                    : null,
                'blocked_requirement_count' => is_numeric($attentionDetails['blocked_requirement_count'] ?? null)
                    ? (int) $attentionDetails['blocked_requirement_count']
                    : null,
            ],
            'plan' => [
                'id' => $basketRun->mealPlan->id,
                'title' => $basketRun->mealPlan->title,
                'starts_on' => $basketRun->mealPlan->starts_on->toDateString(),
                'ends_on' => $basketRun->mealPlan->ends_on->toDateString(),
            ],
            'connection' => $basketRun->connection === null ? null : [
                'id' => $basketRun->connection->id,
                'provider' => $basketRun->connection->provider->value,
                'status' => $basketRun->connection->status->value,
                'owned_by_current_user' => $basketRun->connection->owner_user_id === $user->id,
                'last_verified_at' => $basketRun->connection->last_verified_at?->toIso8601String(),
            ],
            'grocery_plan' => $groceryPlan === null ? null : [
                'id' => $groceryPlan->id,
                'version' => $groceryPlan->version,
                'status' => $groceryPlan->status->value,
                'built_at' => $groceryPlan->built_at?->toIso8601String(),
            ],
            'effective_policy' => [
                'provider' => $policy['provider'] ?? 'coles',
                'home_brand_preference' => $policy['home_brand_preference'] ?? 'allow',
                'bulk_preference' => $policy['bulk_preference'] ?? 'avoid',
                'organic_preference' => $policy['organic_preference'] ?? 'no_preference',
                'preferred_brands' => is_array($policy['preferred_brands'] ?? null)
                    ? array_values($policy['preferred_brands'])
                    : [],
                'basket_target_cents' => $groceryPlan?->effective_basket_target_cents,
                'fingerprint' => $groceryPlan?->purchase_policy_fingerprint,
            ],
            'adjustment' => $adjustment === null ? null : [
                'id' => $adjustment->id,
                'kind' => $adjustment->kind->value,
                'status' => $adjustment->status->value,
                'generated_at' => $adjustment->generated_at->toIso8601String(),
                'items' => $adjustment->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'meal_slot_id' => $item->meal_slot_id,
                    'date' => $item->mealSlot?->date?->toDateString(),
                    'kind' => $item->mealSlot?->kind?->value,
                    'previous_title' => $item->plannedMeal?->title,
                    'replacement_title' => $item->replacement_title,
                    'replacement_summary' => $item->replacement_summary,
                    'estimated_minutes' => $item->estimated_minutes,
                    'estimated_cost_cents' => $item->estimated_cost_cents,
                    'proposal_id' => $item->meal_proposal_id,
                    'proposal_status' => $item->mealProposal?->status?->value,
                ])->values()->all(),
            ],
            'requirements' => $groceryPlan?->requirements
                ->sortBy('display_name')
                ->map(fn ($requirement): array => [
                    'id' => $requirement->id,
                    'name' => $requirement->display_name,
                    'status' => $requirement->status->value,
                    'quantity' => $requirement->quantity,
                    'unit' => $requirement->unit,
                    'quantity_unknown' => $requirement->quantity_unknown,
                ])
                ->values()
                ->all() ?? [],
            'items' => $basketRun->items->sortBy('product_title')->map(fn ($item): array => [
                'id' => $item->id,
                'requirement' => [
                    'id' => $item->requirement->id,
                    'name' => $item->requirement->display_name,
                    'quantity' => $item->requirement->quantity,
                    'unit' => $item->requirement->unit,
                    'quantity_unknown' => $item->requirement->quantity_unknown,
                ],
                'product' => [
                    'sku' => $item->sku,
                    'title' => $item->product_title,
                    'brand' => $item->selection->candidate->brand,
                    'pack_quantity' => $item->selection->candidate->pack_quantity,
                    'pack_unit' => $item->selection->candidate->pack_unit,
                    'absolute_quantity' => $item->absolute_quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'line_price_cents' => $item->line_price_cents,
                ],
                'reasoning' => $item->pack_reasoning,
                'low_confidence' => $item->selection->low_confidence,
                'semantic_tier' => $item->selection->semantic_tier,
                'policy_decisions' => $item->selection->policy_decisions ?? [],
                'policy_exceptions' => $item->selection->material_exceptions ?? [],
                'alternatives' => $this->alternatives($item),
                'can_prefer' => $user->can('update', $basketRun->mealPlan),
                'verified_at' => $item->verified_at?->toIso8601String(),
                'sources' => $item->requirement->sources->map(fn ($source): array => [
                    'planned_meal_id' => $source->planned_meal_id,
                    'meal_title' => $source->plannedMeal->title,
                    'recipe_version_id' => $source->recipe_version_id,
                    'recipe_title' => $source->recipeVersion->title,
                    'recipe_version' => $source->recipeVersion->version,
                    'ingredient_name' => $source->source_name,
                    'scaled_quantity' => $source->scaled_quantity,
                    'unit' => $source->unit,
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'chef_subtotal_cents' => $basketRun->chef_subtotal_cents,
                'retailer_total_cents' => $basketRun->retailer_total_cents,
                'price_notice' => 'Prices are time-sensitive estimates until checkout.',
            ],
            'replaced_line_count' => $basketRun->replaced_line_count,
            'previous_line_count' => $previousLineCount,
            'captured_at' => $basketRun->basket_captured_at?->toIso8601String(),
            'can' => [
                'retry' => $user->can('retry', $basketRun)
                    && in_array($basketRun->status, [
                        BasketRunStatus::Failed,
                        BasketRunStatus::NeedsProduct,
                    ], true),
                'restore' => $user->can('restore', $basketRun)
                    && $baseline !== null
                    && in_array($basketRun->status, [
                        BasketRunStatus::Ready,
                        BasketRunStatus::Failed,
                        BasketRunStatus::Restored,
                        BasketRunStatus::NeedsAttention,
                    ], true),
                'review' => $user->can('reviewInRetailer', $basketRun)
                    && in_array($basketRun->status, [
                        BasketRunStatus::Ready,
                        BasketRunStatus::ProductsSelected,
                        BasketRunStatus::Failed,
                        BasketRunStatus::Uncertain,
                        BasketRunStatus::Restored,
                        BasketRunStatus::NeedsAttention,
                    ], true),
                'override_budget' => $user->can('update', $basketRun)
                    && $basketRun->connection !== null
                    && $basketRun->connection->owner_user_id === $user->id
                    && $basketRun->status === BasketRunStatus::NeedsPlanReview
                    && $basketRun->attention_kind === 'budget_overrun'
                    && $basketRun->budget_overridden_at === null,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function alternatives(BasketRunItem $item): array
    {
        $candidates = $item->requirement->candidates
            ->where('status', RetailerCandidateStatus::Eligible)
            ->where('id', '!=', $item->selection->retailer_product_candidate_id)
            ->sortBy([
                ['price_cents', 'asc'],
                ['title', 'asc'],
            ])
            ->values();
        $alternatives = [];

        foreach ($candidates as $candidate) {
            $pack = $this->chooseBalancedPack->handle(
                $item->requirement,
                collect([$candidate]),
            );
            $difference = $pack['total_price_cents'] - $item->line_price_cents;
            $priceComparison = match (true) {
                $difference < 0 => abs($difference).' cents less for the required quantity',
                $difference > 0 => $difference.' cents more for the required quantity',
                default => 'The same captured total for the required quantity',
            };
            $alternatives[] = [
                'candidate_id' => $candidate->id,
                'sku' => $candidate->sku,
                'title' => $candidate->title,
                'brand' => $candidate->brand,
                'pack_quantity' => $candidate->pack_quantity,
                'pack_unit' => $candidate->pack_unit,
                'pack_count' => $pack['pack_count'],
                'captured_price_cents' => $candidate->price_cents,
                'total_price_cents' => $pack['total_price_cents'],
                'captured_at' => $candidate->captured_at->toIso8601String(),
                'policy_comparison' => $priceComparison.'.',
            ];
        }

        usort($alternatives, fn (array $left, array $right): int => [
            $left['total_price_cents'],
            $left['title'],
        ] <=> [
            $right['total_price_cents'],
            $right['title'],
        ]);

        return array_slice($alternatives, 0, 3);
    }

    private function statusLabel(BasketRunStatus $status): string
    {
        return match ($status) {
            BasketRunStatus::WaitingForRecipes => 'Preparing recipes',
            BasketRunStatus::WaitingForConnection => 'Connect Coles',
            BasketRunStatus::BuildingRequirements => 'Building grocery requirements',
            BasketRunStatus::DiscoveringProducts => 'Finding Coles products',
            BasketRunStatus::SelectingProducts => 'Choosing suitable products',
            BasketRunStatus::PreparingResolution => 'Preparing one plan resolution',
            BasketRunStatus::NeedsPlanReview => 'Revised plan needs review',
            BasketRunStatus::RevalidatingProducts => 'Checking stock and prices',
            BasketRunStatus::ProductsSelected => 'Products selected; basket unchanged',
            BasketRunStatus::ReplacingBasket => 'Replacing the Coles basket',
            BasketRunStatus::Ready => 'Basket ready',
            BasketRunStatus::NeedsProduct => 'Needs a product',
            BasketRunStatus::ReauthenticationRequired => 'Reconnect Coles',
            BasketRunStatus::Failed => 'Preparation stopped',
            BasketRunStatus::Uncertain => 'Basket needs review',
            BasketRunStatus::Restoring => 'Restoring previous basket',
            BasketRunStatus::Restored => 'Previous basket restored',
            BasketRunStatus::NeedsAttention => 'Basket needs attention',
            BasketRunStatus::Cancelled => 'Preparation cancelled',
        };
    }
}
