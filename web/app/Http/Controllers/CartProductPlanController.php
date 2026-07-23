<?php

namespace App\Http\Controllers;

use App\Actions\Automation\BuildCartProductPlan;
use App\Actions\Automation\ContinueApprovedShopping;
use App\Actions\Automation\SelectCartProductCandidate;
use App\Enums\CartProductPlanStatus;
use App\Models\CartProductPlanItem;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartProductPlanController extends Controller
{
    public function store(
        Request $request,
        ShoppingList $shoppingList,
        BuildCartProductPlan $build,
    ): RedirectResponse {
        $validated = $request->validate([
            'shopping_list_revision_id' => ['required', 'integer'],
            'retailer_connection_id' => ['required', 'integer'],
        ]);
        $revision = ShoppingListRevision::query()
            ->where('team_id', $shoppingList->team_id)
            ->where('shopping_list_id', $shoppingList->id)
            ->whereKey($validated['shopping_list_revision_id'])
            ->firstOrFail();
        $connection = RetailerConnection::query()
            ->where('team_id', $shoppingList->team_id)
            ->whereKey($validated['retailer_connection_id'])
            ->firstOrFail();
        $plan = $build->handle($shoppingList, $revision, $connection, $request->user(), force: true);

        return to_route('meal-plans.show', ['mealPlan' => $shoppingList->meal_plan_id, 'phase' => 'shopping'])
            ->with(
                $plan->status === CartProductPlanStatus::Ready ? 'success' : 'warning',
                $plan->status === CartProductPlanStatus::Ready
                    ? 'The exact Woolworths product plan is ready to review.'
                    : 'Review the ambiguous or unresolved Woolworths product choices before preparing the cart.',
            );
    }

    public function update(
        Request $request,
        CartProductPlanItem $cartProductPlanItem,
        SelectCartProductCandidate $select,
        ContinueApprovedShopping $continueApprovedShopping,
    ): RedirectResponse {
        $validated = $request->validate(['candidate_index' => ['required', 'integer', 'min:0']]);
        $plan = $select->handle($cartProductPlanItem, $request->user(), $validated['candidate_index']);

        if ($plan->status === CartProductPlanStatus::Ready) {
            $continueApprovedShopping->handle($plan->shoppingList->mealPlan, $request->user());
        }

        return to_route('meal-plans.show', [
            'mealPlan' => $plan->shoppingList->meal_plan_id,
            'phase' => 'shopping',
        ])->with('success', $plan->status === CartProductPlanStatus::Ready
            ? 'The exact Woolworths product plan is ready to review.'
            : 'The Woolworths product choice was saved.');
    }
}
