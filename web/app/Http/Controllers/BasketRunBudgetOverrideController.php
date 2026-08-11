<?php

namespace App\Http\Controllers;

use App\Actions\Baskets\OverrideBasketBudget;
use App\Models\BasketRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BasketRunBudgetOverrideController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        BasketRun $basketRun,
        OverrideBasketBudget $overrideBudget,
    ): JsonResponse {
        $run = $overrideBudget->handle($basketRun, $request->user());

        return response()->json(['basket_run' => [
            'id' => $run->id,
            'status' => $run->status->value,
            'budget_override_cents' => $run->budget_override_cents,
            'budget_overridden_at' => $run->budget_overridden_at?->toIso8601String(),
        ]]);
    }
}
