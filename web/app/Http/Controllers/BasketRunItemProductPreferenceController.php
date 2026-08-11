<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\RememberRetailerProductPreference;
use App\Http\Requests\RememberRetailerProductPreferenceRequest;
use App\Models\BasketRun;
use App\Models\BasketRunItem;
use App\Models\RetailerProductCandidate;
use Illuminate\Http\JsonResponse;

class BasketRunItemProductPreferenceController extends Controller
{
    public function __invoke(
        RememberRetailerProductPreferenceRequest $request,
        BasketRun $basketRun,
        BasketRunItem $basketRunItem,
        RememberRetailerProductPreference $rememberPreference,
    ): JsonResponse {
        $candidate = RetailerProductCandidate::query()->findOrFail(
            $request->integer('retailer_product_candidate_id'),
        );
        $preference = $rememberPreference->handle(
            $basketRun,
            $basketRunItem,
            $candidate,
            $request->user(),
        );

        return response()->json(['preference' => [
            'id' => $preference->id,
            'sku' => $preference->sku,
            'product_title' => $preference->product_title,
            'revoked' => false,
        ]]);
    }
}
