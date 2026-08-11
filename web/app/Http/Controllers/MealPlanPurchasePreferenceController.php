<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\UpdateMealPlanPurchasePreference;
use App\Http\Requests\UpdateMealPlanPurchasePreferenceRequest;
use App\Models\MealPlan;
use Illuminate\Http\JsonResponse;

class MealPlanPurchasePreferenceController extends Controller
{
    public function __invoke(
        UpdateMealPlanPurchasePreferenceRequest $request,
        MealPlan $mealPlan,
        UpdateMealPlanPurchasePreference $updatePreference,
    ): JsonResponse {
        $preference = $updatePreference->handle(
            $mealPlan,
            $request->user(),
            $request->integer('basket_target_cents') ?: null,
        );

        return response()->json(['purchase_preference' => [
            'id' => $preference->id,
            'provider' => $preference->provider->value,
            'basket_target_cents' => $preference->basket_target_cents,
        ]]);
    }
}
