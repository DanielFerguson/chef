<?php

namespace App\Http\Controllers;

use App\Models\OrderLine;
use App\Models\PlannedMeal;
use App\Models\ShoppingListItemSource;
use Inertia\Inertia;
use Inertia\Response;

class CookingController extends Controller
{
    public function show(PlannedMeal $plannedMeal): Response
    {
        $this->authorize('view', $plannedMeal);
        $plannedMeal->load([
            'mealPlan:id,title,starts_on,ends_on',
            'mealSlot.participants:id,name',
            'recipeVersion.recipe:id,title',
            'recipeVersion.ingredients',
            'recipeVersion.steps',
            'recipeVersion.equipment',
            'recipeVersion.preparationNotices',
            'outcome.feedback.person:id,name',
        ]);

        $sources = ShoppingListItemSource::query()
            ->where('team_id', $plannedMeal->team_id)
            ->where('planned_meal_id', $plannedMeal->id)
            ->with(['item.productMatch.retailProduct.retailer'])
            ->get();
        $itemIds = $sources->pluck('shopping_list_item_id')->unique()->values();
        $orderLines = OrderLine::query()
            ->where('team_id', $plannedMeal->team_id)
            ->whereIn('shopping_list_item_id', $itemIds)
            ->latest('id')
            ->get()
            ->unique('shopping_list_item_id')
            ->keyBy('shopping_list_item_id');

        $shoppingChoices = $sources->map(function (ShoppingListItemSource $source) use ($orderLines): array {
            $item = $source->item;
            $orderLine = $orderLines->get($item->id);
            $product = $item->productMatch?->retailProduct;

            return [
                'ingredient' => $item->name,
                'product' => $orderLine instanceof OrderLine ? $orderLine->product_name : $product?->name,
                'brand' => $orderLine instanceof OrderLine ? $orderLine->brand : $product?->brand,
                'substituted_from' => $orderLine instanceof OrderLine ? $orderLine->substituted_from_name : null,
                'retailer' => $product?->retailer?->name,
            ];
        })->filter(fn (array $choice) => $choice['product'] !== null)->unique('ingredient')->values();

        return Inertia::render('cooking/show', [
            'meal' => $plannedMeal,
            'shoppingChoices' => $shoppingChoices,
        ]);
    }
}
