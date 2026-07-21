<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Data\ProviderSession;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrowserbaseBrowserSessionProvider implements BrowserSessionProvider
{
    public function createContext(): string
    {
        $response = $this->client()->post('/v1/contexts', [
            'projectId' => $this->requiredConfig('project_id'),
        ])->throw();

        return $this->requiredString($response->json(), 'id');
    }

    public function createSession(RetailerConnection $connection, BrowserSessionPurpose $purpose): ProviderSession
    {
        $contextId = $connection->provider_context_id;

        if (! is_string($contextId) || $contextId === '') {
            throw new RuntimeException('The retailer connection does not have a Browserbase Context.');
        }

        $response = $this->client()->post('/v1/sessions', [
            'projectId' => $this->requiredConfig('project_id'),
            'browserSettings' => [
                'context' => ['id' => $contextId, 'persist' => true],
                'recordSession' => false,
                'viewport' => [
                    'width' => (int) config('services.browserbase.viewport_width', 1280),
                    'height' => (int) config('services.browserbase.viewport_height', 900),
                ],
            ],
            'timeout' => (int) config('services.browserbase.session_timeout', 900),
            'keepAlive' => true,
            'region' => (string) config('services.browserbase.region', 'ap-southeast-1'),
            'proxies' => [[
                'type' => 'browserbase',
                'geolocation' => [
                    'city' => (string) config('services.browserbase.proxy_city', 'MELBOURNE'),
                    'country' => (string) config('services.browserbase.proxy_country', 'AU'),
                ],
            ]],
            'userMetadata' => [
                'chef_connection_id' => (string) $connection->id,
                'purpose' => $purpose->value,
            ],
        ]);

        if (in_array($response->status(), [404, 410], true)) {
            throw new RetailerContextRevokedException;
        }

        $response->throw();

        $payload = $response->json();
        $expiresAt = isset($payload['expiresAt']) && is_string($payload['expiresAt'])
            ? new DateTimeImmutable($payload['expiresAt'])
            : null;

        return new ProviderSession($this->requiredString($payload, 'id'), $expiresAt);
    }

    public function connectionUrl(BrowserSession $session): string
    {
        $payload = $this->sessionPayload($session);

        return $this->requiredString($payload, 'connectUrl');
    }

    public function liveViewUrl(BrowserSession $session): string
    {
        $response = $this->client()
            ->get('/v1/sessions/'.rawurlencode($session->provider_session_id).'/debug');

        if (in_array($response->status(), [404, 410], true)) {
            throw new BrowserSessionLostException;
        }

        $response->throw();

        return $this->requiredString($response->json(), 'debuggerFullscreenUrl');
    }

    public function close(BrowserSession $session): void
    {
        $response = $this->client()->post('/v1/sessions/'.rawurlencode($session->provider_session_id), [
            'status' => 'REQUEST_RELEASE',
            'projectId' => $this->requiredConfig('project_id'),
        ]);

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $response->throw();
    }

    public function deleteContext(RetailerConnection $connection): void
    {
        $contextId = $connection->provider_context_id;

        if (! is_string($contextId) || $contextId === '') {
            return;
        }

        $response = $this->client()->delete('/v1/contexts/'.rawurlencode($contextId));

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $response->throw();
    }

    /** @return array<string, mixed> */
    private function sessionPayload(BrowserSession $session): array
    {
        $response = $this->client()
            ->get('/v1/sessions/'.rawurlencode($session->provider_session_id));

        if (in_array($response->status(), [404, 410], true)) {
            throw new BrowserSessionLostException;
        }

        return $response->throw()->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.browserbase.base_url', 'https://api.browserbase.com'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-BB-API-Key' => $this->requiredConfig('api_key')])
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function requiredConfig(string $key): string
    {
        $value = config('services.browserbase.'.$key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Browserbase is not configured.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Browserbase returned an incomplete response.');
        }

        return $value;
    }
}
