<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\BuildRetailerPurchasePolicyView;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Http\Requests\UpdateRetailerPurchasePolicyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerPurchasePolicyController extends Controller
{
    public function show(Request $request, BuildRetailerPurchasePolicyView $buildView): JsonResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);

        return response()->json($buildView->handle($team, $request->user()));
    }

    public function update(
        UpdateRetailerPurchasePolicyRequest $request,
        UpdateRetailerPurchasePolicy $updatePolicy,
        BuildRetailerPurchasePolicyView $buildView,
    ): JsonResponse {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $validated = $request->validated();
        $updatePolicy->handle(
            $team,
            $request->user(),
            RetailerProvider::Coles,
            RetailerHomeBrandPreference::from($validated['home_brand_preference']),
            RetailerBulkPreference::from($validated['bulk_preference']),
            RetailerOrganicPreference::from($validated['organic_preference']),
            $validated['preferred_brands'],
            $validated['default_basket_target_cents'] ?? null,
        );

        return response()->json($buildView->handle($team, $request->user()));
    }
}
