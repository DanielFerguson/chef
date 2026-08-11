<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\VerifyRetailerConnection;
use App\Http\Requests\VerifyRetailerConnectionRequest;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Http\JsonResponse;

class RetailerConnectionVerificationController extends Controller
{
    public function __invoke(
        VerifyRetailerConnectionRequest $request,
        RetailerConnection $retailerConnection,
        VerifyRetailerConnection $verify,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $connection = $verify->handle($retailerConnection, $request->user(), $gateway);

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status->value,
                'standing_consent' => true,
                'last_verified_at' => $connection->last_verified_at?->toIso8601String(),
            ],
        ]);
    }
}
