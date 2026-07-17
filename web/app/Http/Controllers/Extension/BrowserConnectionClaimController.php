<?php

namespace App\Http\Controllers\Extension;

use App\Actions\Automation\ClaimBrowserConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrowserConnectionClaimController extends Controller
{
    public function __invoke(Request $request, ClaimBrowserConnection $claim): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $claimed = $claim->handle($validated['pairing_code'], $validated['name']);

        return response()->json([
            'connection' => [
                'uuid' => $claimed['connection']->uuid,
                'name' => $claimed['connection']->name,
                'allowed_origins' => $claimed['connection']->allowed_origins,
                'expires_at' => $claimed['connection']->expires_at->toIso8601String(),
            ],
            'token' => $claimed['token'],
        ]);
    }
}
