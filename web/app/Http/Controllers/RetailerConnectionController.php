<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\DisconnectRetailerConnection;
use App\Actions\Retailers\RevokeRetailerAutomationGrant;
use App\Actions\Retailers\StartRetailerConnection;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerConnectionController extends Controller
{
    public function store(
        Request $request,
        StartRetailerConnection $startConnection,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $user = $request->user();
        $team = $user->currentTeam;
        abort_unless($team !== null, 404);
        $result = $startConnection->handle($team, $user, $gateway);
        $requiresStandingConsent = ! $result['connection']->grants()
            ->whereNull('revoked_at')
            ->exists();

        return response()->json([
            'connection' => [
                'id' => $result['connection']->id,
                'status' => $result['connection']->status->value,
                'requires_standing_consent' => $requiresStandingConsent,
            ],
            'session' => [
                'live_view_url' => $result['session']->liveViewUrl,
                'expires_at' => $result['session']->expiresAt->toIso8601String(),
            ],
            'consent' => [
                'version' => config('retailer.consent.disclosure_version'),
                'disclosure' => config('retailer.consent.disclosure'),
                'links' => config('retailer.consent.links'),
            ],
        ], 201);
    }

    public function destroy(
        Request $request,
        RetailerConnection $retailerConnection,
        DisconnectRetailerConnection $disconnect,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $connection = $disconnect->handle($retailerConnection, $request->user(), $gateway);

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status->value,
            ],
        ]);
    }

    public function revokeGrant(
        Request $request,
        RetailerConnection $retailerConnection,
        RevokeRetailerAutomationGrant $revokeGrant,
    ): JsonResponse {
        $connection = $revokeGrant->handle($retailerConnection, $request->user());

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'standing_consent' => false,
            ],
        ]);
    }
}
