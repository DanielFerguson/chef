<?php

namespace App\Http\Controllers;

use App\Actions\Automation\DisconnectRetailerConnection;
use App\Actions\Automation\StartRetailerConnection;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetailerConnectionController extends Controller
{
    public function store(Request $request, ShoppingList $shoppingList, StartRetailerConnection $start): RedirectResponse
    {
        $this->authorize('update', $shoppingList);
        $session = $start->handle($shoppingList->team, $request->user());
        $session->update([
            'metadata' => [
                ...($session->metadata ?? []),
                'return_shopping_list_id' => $shoppingList->id,
            ],
        ]);

        return to_route('browser-sessions.authenticate.show', $session);
    }

    public function destroy(
        Request $request,
        RetailerConnection $retailerConnection,
        DisconnectRetailerConnection $disconnect,
    ): RedirectResponse {
        $returnUrl = $this->shoppingUrl($retailerConnection);
        $disconnect->handle($retailerConnection, $request->user());

        return redirect($returnUrl)->with('success', 'Woolworths was disconnected.');
    }

    private function shoppingUrl(RetailerConnection $connection): string
    {
        $run = $connection->orderRuns()->latest('id')->first();

        if ($run !== null) {
            return route('meal-plans.show', [
                'mealPlan' => $run->shoppingList->meal_plan_id,
                'phase' => 'shopping',
            ]);
        }

        $shoppingList = ShoppingList::query()
            ->where('team_id', $connection->team_id)
            ->latest('id')
            ->first();

        if ($shoppingList !== null) {
            return route('meal-plans.show', [
                'mealPlan' => $shoppingList->meal_plan_id,
                'phase' => 'shopping',
            ]);
        }

        return route('shopping.index');
    }
}
