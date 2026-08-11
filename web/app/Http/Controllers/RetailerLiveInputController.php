<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\RelayRetailerLiveInput;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerLiveInputController extends Controller
{
    public function __invoke(
        Request $request,
        RetailerConnection $retailerConnection,
        RelayRetailerLiveInput $relayInput,
        RetailerAutomationGateway $gateway,
    ): JsonResponse {
        $payload = $request->json()->all();
        $text = array_key_exists('text', $payload) && is_string($payload['text'])
            ? $payload['text']
            : null;
        $key = array_key_exists('key', $payload) && is_string($payload['key'])
            ? $payload['key']
            : null;

        $relayInput->handle(
            $retailerConnection,
            $request->user(),
            $gateway,
            $text,
            $key,
        );

        return response()->json([
            'forwarded' => true,
            'kind' => $text !== null ? 'text' : 'key',
        ]);
    }
}
