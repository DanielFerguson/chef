<?php

namespace App\Http\Controllers;

use App\Actions\Retailer\CancelRetailerOrderRun;
use App\Actions\Retailer\ConfirmRetailerOrder;
use App\Actions\Retailer\ResolveCartDecision;
use App\Actions\Retailer\SelectFulfilmentSlot;
use App\Actions\Retailer\StartRetailerOrderRun;
use App\Actions\Retailer\VerifyRetailerPlacement;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetailerOrderRunController extends Controller
{
    public function store(
        Request $request,
        ShoppingList $shoppingList,
        StartRetailerOrderRun $start,
    ): RedirectResponse {
        $request->validate([
            'shopping_list_revision_id' => ['required', 'integer'],
            'retailer_connection_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
            'safety_acknowledged' => ['required', 'accepted'],
            'product_plan_reviewed' => ['required_without:allow_automatic_product_search', 'boolean'],
            'allow_automatic_product_search' => ['sometimes', 'boolean'],
        ]);
        $revision = ShoppingListRevision::query()
            ->where('team_id', $shoppingList->team_id)
            ->where('shopping_list_id', $shoppingList->id)
            ->findOrFail($request->integer('shopping_list_revision_id'));
        $connection = RetailerConnection::query()
            ->where('team_id', $shoppingList->team_id)
            ->findOrFail($request->integer('retailer_connection_id'));

        $start->handle(
            $shoppingList,
            $revision,
            $connection,
            $request->user(),
            $request->string('idempotency_key')->toString(),
            $request->boolean('safety_acknowledged'),
            $request->boolean('product_plan_reviewed') || $request->boolean('allow_automatic_product_search'),
        );

        return to_route('meal-plans.shopping.show', $shoppingList->meal_plan_id);
    }

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
