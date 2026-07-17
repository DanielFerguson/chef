<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), payment=(), usb=(), microphone=(self)');

        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if (app()->isProduction() && $response->headers->get('Content-Type') !== null) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $connectSources = ["'self'", 'https://api.openai.com', 'wss://api.openai.com'];
        $reverbHost = (string) config('broadcasting.connections.reverb.options.host');

        if (preg_match('/\A[a-z0-9.-]+\z/i', $reverbHost) === 1) {
            $connectSources[] = 'https://'.$reverbHost;
            $connectSources[] = 'wss://'.$reverbHost;
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data: blob:",
            'connect-src '.implode(' ', array_unique($connectSources)),
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            'upgrade-insecure-requests',
        ]).';';
    }
}
