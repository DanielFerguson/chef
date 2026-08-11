<?php

namespace App\Jobs;

use App\Actions\Retailers\SelectRetailerProducts;
use App\Enums\BasketRunStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Models\BasketRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SelectRetailerProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $basketRunId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'retailer-product-selection:'.$this->basketRunId;
    }

    public function handle(SelectRetailerProducts $selectProducts): void
    {
        $run = BasketRun::query()->with('groceryPlan')->findOrFail($this->basketRunId);
        if (! $selectProducts->handle($run->groceryPlan)) {
            $run->refresh();
            if (config('retailer.features.ai_recovery', false)) {
                $run->update([
                    'status' => BasketRunStatus::PreparingResolution,
                    'attention_kind' => MealPlanAdjustmentKind::ProductUnavailable->value,
                    'attention_details' => [
                        'blocked_requirement_count' => $run->groceryPlan->requirements()
                            ->where('status', 'needs_product')
                            ->count(),
                    ],
                ]);
                PrepareMealPlanAdjustmentDraftJob::dispatch(
                    $run->id,
                    MealPlanAdjustmentKind::ProductUnavailable,
                );
            }

            return;
        }

        $run->refresh();
        if ($run->items()->doesntExist()) {
            $run->update([
                'status' => BasketRunStatus::Ready,
                'chef_subtotal_cents' => 0,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return;
        }

        $budgetTarget = $run->groceryPlan->effective_basket_target_cents;
        if ($budgetTarget !== null
            && $run->chef_subtotal_cents !== null
            && $run->chef_subtotal_cents > $budgetTarget
            && $run->budget_overridden_at === null) {
            $run->update([
                'status' => config('retailer.features.ai_recovery', false)
                    ? BasketRunStatus::PreparingResolution
                    : BasketRunStatus::NeedsAttention,
                'attention_kind' => MealPlanAdjustmentKind::BudgetOverrun->value,
                'attention_details' => [
                    'budget_target_cents' => $budgetTarget,
                    'selected_subtotal_cents' => $run->chef_subtotal_cents,
                ],
                'failure_code' => config('retailer.features.ai_recovery', false)
                    ? null
                    : 'budget_overrun',
                'failure_message' => config('retailer.features.ai_recovery', false)
                    ? null
                    : 'Chef stopped before changing Coles because the selected products exceeded the basket target.',
            ]);
            if (config('retailer.features.ai_recovery', false)) {
                PrepareMealPlanAdjustmentDraftJob::dispatch(
                    $run->id,
                    MealPlanAdjustmentKind::BudgetOverrun,
                );
            }

            return;
        }

        if (config('retailer.features.mutation', false)
            && ! config('retailer.features.mutation_circuit_breaker', true)) {
            ReplaceBasketJob::dispatch($run->id);

            return;
        }

        $run->update([
            'status' => BasketRunStatus::ProductsSelected,
            'failure_code' => null,
            'failure_message' => 'Chef selected valid Coles products, but basket changes are paused for this rollout. The existing basket was not changed.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        BasketRun::query()
            ->whereKey($this->basketRunId)
            ->whereIn('status', [
                BasketRunStatus::SelectingProducts->value,
                BasketRunStatus::RevalidatingProducts->value,
            ])
            ->update([
                'status' => BasketRunStatus::Failed->value,
                'failure_code' => 'product_selection_failed',
                'failure_message' => 'Chef stopped after it could not finish choosing Coles products. Try preparing the basket again.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);
    }
}
