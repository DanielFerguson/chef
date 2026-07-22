<?php

namespace App\Http\Controllers;

use App\Actions\Retailer\CancelRetailerOrderRun;
use App\Actions\Retailer\ConfirmRetailerOrder;
use App\Actions\Retailer\ResolveCartDecision;
use App\Actions\Retailer\SelectFulfilmentSlot;
use App\Actions\Retailer\VerifyRetailerPlacement;
use App\Models\RetailerOrderRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetailerOrderRunController extends Controller
{
    public function selectFulfilment(
        Request $request,
        RetailerOrderRun $retailerOrderRun,
        SelectFulfilmentSlot $select,
    ): RedirectResponse {
        $validated = $request->validate([
            'slot_id' => ['required', 'string'],
            'fulfilment_type' => ['required', 'string', 'in:delivery,pickup'],
        ]);

        $select->handle(
            $retailerOrderRun,
            $request->user(),
            $validated['slot_id'],
            $validated['fulfilment_type'],
        );

        return to_route('meal-plans.shopping.show', $retailerOrderRun->shoppingList->meal_plan_id);
    }

    public function confirm(
        Request $request,
        RetailerOrderRun $retailerOrderRun,
        ConfirmRetailerOrder $confirm,
    ): RedirectResponse {
        $confirm->handle($retailerOrderRun, $request->user());

        return to_route('meal-plans.shopping.show', $retailerOrderRun->shoppingList->meal_plan_id);
    }

    public function verify(
        Request $request,
        RetailerOrderRun $retailerOrderRun,
        VerifyRetailerPlacement $verify,
    ): RedirectResponse {
        $validated = $request->validate([
            'retailer_order_reference' => ['nullable', 'string', 'max:255'],
            'acknowledged_placed' => ['sometimes', 'boolean'],
        ]);

        $verify->handle(
            $retailerOrderRun,
            $request->user(),
            retailerOrderReference: $validated['retailer_order_reference'] ?? null,
            acknowledgedPlaced: (bool) ($validated['acknowledged_placed'] ?? false),
        );

        return to_route('meal-plans.shopping.show', $retailerOrderRun->shoppingList->meal_plan_id);
    }

    public function destroy(
        Request $request,
        RetailerOrderRun $retailerOrderRun,
        CancelRetailerOrderRun $cancel,
    ): RedirectResponse {
        $cancel->handle($retailerOrderRun, $request->user());

        return to_route('meal-plans.shopping.show', $retailerOrderRun->shoppingList->meal_plan_id)
            ->with('success', 'Woolworths order run was cancelled.');
    }

    public function resolveCartDecision(
        Request $request,
        RetailerOrderRun $retailerOrderRun,
        ResolveCartDecision $resolve,
    ): RedirectResponse {
        $validated = $request->validate([
            'choice' => ['required', 'string', 'in:merge,replace,cancel'],
        ]);

        $resolve->handle($retailerOrderRun, $request->user(), $validated['choice']);

        return to_route('meal-plans.shopping.show', $retailerOrderRun->shoppingList->meal_plan_id);
    }
}
