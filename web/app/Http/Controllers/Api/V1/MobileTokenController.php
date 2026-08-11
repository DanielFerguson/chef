<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class MobileTokenController extends Controller
{
    public function store(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if (! isset($validated['code']) && ! isset($validated['recovery_code'])) {
                return response()->json([
                    'message' => 'Two-factor authentication is required.',
                    'two_factor_required' => true,
                ], 422);
            }

            $validCode = isset($validated['code']) && $twoFactor->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $validated['code'],
            );
            $validRecoveryCode = isset($validated['recovery_code'])
                ? collect($user->recoveryCodes())->first(
                    fn (string $code) => hash_equals($code, $validated['recovery_code']),
                )
                : null;

            if (! $validCode && $validRecoveryCode === null) {
                throw ValidationException::withMessages([
                    'code' => 'The provided two-factor authentication code is invalid.',
                ]);
            }

            if ($validRecoveryCode !== null) {
                $user->replaceRecoveryCode($validRecoveryCode);
            }
        }

        return $this->tokenResponse($user, $validated['device_name']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['revoked' => true]);
    }

    public function tokenResponse(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addDays(30);
        $token = $user->createToken("iOS: {$deviceName}", ['mobile'], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], $status);
    }
}
