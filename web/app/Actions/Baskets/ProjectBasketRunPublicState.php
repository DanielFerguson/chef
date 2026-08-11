<?php

namespace App\Actions\Baskets;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;

class ProjectBasketRunPublicState
{
    /** @return array{state: string, outcome: string|null} */
    public function handle(BasketRun $basketRun): array
    {
        $state = match ($basketRun->status) {
            BasketRunStatus::WaitingForConnection,
            BasketRunStatus::ReauthenticationRequired => 'connection_required',
            BasketRunStatus::NeedsPlanReview => 'plan_review_required',
            BasketRunStatus::Ready,
            BasketRunStatus::ProductsSelected,
            BasketRunStatus::Restored => 'ready',
            BasketRunStatus::NeedsProduct,
            BasketRunStatus::Uncertain,
            BasketRunStatus::NeedsAttention,
            BasketRunStatus::Cancelled => 'needs_attention',
            BasketRunStatus::Failed => 'failed',
            default => 'preparing',
        };
        $outcome = match ($basketRun->status) {
            BasketRunStatus::Ready => 'basket_ready',
            BasketRunStatus::ProductsSelected => 'products_selected',
            BasketRunStatus::Restored => 'basket_restored',
            BasketRunStatus::Cancelled => 'cancelled',
            default => null,
        };

        return compact('state', 'outcome');
    }
}
