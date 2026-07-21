<?php

namespace App\Http\Controllers;

use App\Actions\Automation\CancelAutomationRun;
use App\Actions\Automation\StartCartPreparation;
use App\Models\AutomationRun;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomationRunController extends Controller
{
    public function store(
        Request $request,
        ShoppingList $shoppingList,
        StartCartPreparation $start,
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

    public function destroy(
        Request $request,
        AutomationRun $automationRun,
        CancelAutomationRun $cancel,
    ): RedirectResponse {
        $cancel->handle($automationRun, $request->user());

        return to_route('meal-plans.shopping.show', $automationRun->shoppingList->meal_plan_id)
            ->with('success', 'Cart preparation was cancelled.');
    }
}
