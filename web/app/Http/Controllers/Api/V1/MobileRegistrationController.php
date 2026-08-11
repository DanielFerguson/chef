<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileRegistrationController extends Controller
{
    public function __invoke(
        Request $request,
        CreateNewUser $createNewUser,
        MobileTokenController $tokens,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);
        $user = $createNewUser->create($validated);

        return $tokens->tokenResponse($user, $validated['device_name'], 201);
    }
}
