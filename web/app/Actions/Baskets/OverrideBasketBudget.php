<?php

namespace App\Actions\Baskets;

use App\Enums\BasketRunStatus;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\MealProposalStatus;
use App\Jobs\ReplaceBasketJob;
use App\Models\BasketRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OverrideBasketBudget
{
    public function handle(BasketRun $basketRun, User $user): BasketRun
    {
        if (! $user->can('update', $basketRun)) {
            throw new AuthorizationException('Only the connected Coles account owner can accept this basket target exception.');
        }

        $run = DB::transaction(function () use ($basketRun, $user): BasketRun {
            $locked = BasketRun::query()
                ->with(['connection', 'adjustmentDrafts.items.mealProposal', 'groceryPlan'])
                ->lockForUpdate()
                ->findOrFail($basketRun->id);
            if ($locked->connection === null || $locked->connection->owner_user_id !== $user->id) {
                throw new AuthorizationException('Only the connected Coles account owner can accept this basket target exception.');
            }
            if ($locked->budget_overridden_at !== null) {
                return $locked;
            }
            if (! in_array($locked->status, [BasketRunStatus::NeedsPlanReview, BasketRunStatus::NeedsAttention], true)
                || $locked->attention_kind !== MealPlanAdjustmentKind::BudgetOverrun->value
                || $locked->chef_subtotal_cents === null
                || $locked->groceryPlan?->effective_basket_target_cents === null
                || $locked->chef_subtotal_cents <= $locked->groceryPlan->effective_basket_target_cents
                || $locked->mutation_started_at !== null
                || $locked->snapshots()->exists()) {
                throw ValidationException::withMessages([
                    'basket_run' => 'This basket run is not waiting for an owner budget decision before mutation.',
                ]);
            }

            $draft = $locked->adjustmentDrafts
                ->first(fn ($candidate) => $candidate->kind === MealPlanAdjustmentKind::BudgetOverrun
                    && $candidate->status === MealPlanAdjustmentDraftStatus::Pending);
            if ($draft !== null) {
                foreach ($draft->items as $item) {
                    if ($item->mealProposal?->status === MealProposalStatus::Pending) {
                        $item->mealProposal->update([
                            'status' => MealProposalStatus::Replaced,
                            'decided_by_user_id' => $user->id,
                            'decided_at' => now(),
                        ]);
                    }
                }
                $draft->update([
                    'status' => MealPlanAdjustmentDraftStatus::Superseded,
                    'superseded_at' => now(),
                ]);
            }

            $locked->update([
                'status' => BasketRunStatus::RevalidatingProducts,
                'budget_override_cents' => $locked->chef_subtotal_cents,
                'budget_override_by_user_id' => $user->id,
                'budget_overridden_at' => now(),
                'attention_kind' => null,
                'attention_details' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $locked->refresh();
        });

        if (config('retailer.features.mutation', false)
            && ! config('retailer.features.mutation_circuit_breaker', true)) {
            ReplaceBasketJob::dispatch($run->id);
        } else {
            $run->update([
                'status' => BasketRunStatus::ProductsSelected,
                'failure_message' => 'Chef selected valid Coles products, but basket changes are paused for this rollout. The existing basket was not changed.',
            ]);
        }

        return $run->refresh();
    }
}
