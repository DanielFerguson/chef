<?php

namespace App\Http\Controllers;

use App\Actions\Retailers\RevokeRetailerProductPreference;
use App\Models\RetailerProductPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailerProductPreferenceController extends Controller
{
    public function __invoke(
        Request $request,
        RetailerProductPreference $preference,
        RevokeRetailerProductPreference $revokePreference,
    ): JsonResponse {
        $preference = $revokePreference->handle($preference, $request->user());

        return response()->json(['preference' => [
            'id' => $preference->id,
            'revoked' => $preference->revoked_at !== null,
        ]]);
    }
}
