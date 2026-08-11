<?php

namespace App\Jobs;

use App\Actions\Retailers\PrepareMealPlanAdjustmentDraft;
use App\Enums\BasketRunStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Models\BasketRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareMealPlanAdjustmentDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $basketRunId,
        public readonly MealPlanAdjustmentKind $kind,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'meal-plan-adjustment:'.$this->basketRunId.':'.$this->kind->value;
    }

    public function handle(PrepareMealPlanAdjustmentDraft $prepareDraft): void
    {
        $run = BasketRun::query()->findOrFail($this->basketRunId);
        if (! config('retailer.features.ai_recovery', false)) {
            $this->fallback($run);

            return;
        }

        try {
            $prepareDraft->handle($run, $this->kind);
        } catch (Throwable) {
            $this->fallback($run->refresh());
        }
    }

    private function fallback(BasketRun $run): void
    {
        $run->update([
            'status' => $this->kind === MealPlanAdjustmentKind::ProductUnavailable
                ? BasketRunStatus::NeedsProduct
                : BasketRunStatus::Failed,
            'attention_kind' => $this->kind->value,
            'failure_code' => $this->kind === MealPlanAdjustmentKind::ProductUnavailable
                ? 'no_valid_candidate'
                : 'budget_resolution_unavailable',
            'failure_message' => $this->kind === MealPlanAdjustmentKind::ProductUnavailable
                ? 'Chef could not find a valid product or prepare a safe replacement plan. Review the affected grocery item.'
                : 'Chef stopped before changing Coles because the selected products exceeded the basket target and no safe cheaper plan was available.',
        ]);
    }
}
