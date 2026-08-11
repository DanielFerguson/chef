<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\ReleaseRetailerLiveSession;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerLiveSessionController extends Controller
{
    public function __invoke(
        Request $request,
        RetailerConnection $retailerConnection,
        ReleaseRetailerLiveSession $releaseSession,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $releaseSession->handle($retailerConnection, $request->user(), $gateway);

        return response()->json(['released' => true]);
    }
}
