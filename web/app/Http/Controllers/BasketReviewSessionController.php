<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\StartRetailerLiveSession;
use App\Models\BasketRun;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BasketReviewSessionController extends Controller
{
    public function __invoke(
        Request $request,
        BasketRun $basketRun,
        StartRetailerLiveSession $startLiveSession,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $this->authorize('reviewInRetailer', $basketRun);
        $connection = $basketRun->connection;
        abort_unless($connection !== null, 422, 'Reconnect Coles before reviewing the basket.');
        $session = $startLiveSession->handle(
            $connection,
            $request->user(),
            $gateway,
            'review',
        );

        return response()->json([
            'session' => [
                'live_view_url' => $session->liveViewUrl,
                'expires_at' => $session->expiresAt->toIso8601String(),
            ],
        ]);
    }
}
