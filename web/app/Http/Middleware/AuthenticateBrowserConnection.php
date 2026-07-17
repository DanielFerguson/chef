<?php

namespace App\Http\Middleware;

use App\Automation\ConnectionCredentials;
use App\Enums\BrowserConnectionStatus;
use App\Models\BrowserConnection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrowserConnection
{
    public function __construct(private readonly ConnectionCredentials $credentials) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Chef-Connection-Token');
        $connection = $token === '' ? null : BrowserConnection::query()
            ->where('token_hash', $this->credentials->tokenHash($token))
            ->where('status', BrowserConnectionStatus::Active)
            ->where('expires_at', '>', now())
            ->first();

        if ($connection === null) {
            return new JsonResponse(['message' => 'The Chef browser connection is invalid or expired.'], 401);
        }

        $request->attributes->set('browser_connection', $connection);

        return $next($request);
    }
}
