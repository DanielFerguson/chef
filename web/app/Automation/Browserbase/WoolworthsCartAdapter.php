<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Models\BrowserSession;
use RuntimeException;

class WoolworthsCartAdapter implements RetailerCartAdapter
{
    public function __construct(private readonly ComputerExecutor $executor) {}

    public function retailerSlug(): string
    {
        return 'woolworths';
    }

    public function loginUrl(): string
    {
        return (string) config('services.woolworths.login_url', 'https://www.woolworths.com.au/shop/securelogin');
    }

    public function cartUrl(): string
    {
        return (string) config('services.woolworths.cart_url', 'https://www.woolworths.com.au/shop/checkout/cart');
    }

    public function openLogin(BrowserSession $session): void
    {
        $this->executor->resumeControl($session);

        try {
            $this->requireSuccess($this->executor->execute($session, new WorkerCommand('navigate', [
                'url' => $this->loginUrl(),
                'mode' => 'human_login',
            ])));
        } finally {
            $this->executor->yieldControl($session);
        }
    }

    public function checkAuthentication(BrowserSession $session): AuthenticationCheck
    {
        $workerResult = $this->executor->execute($session, new WorkerCommand('probe_authentication', [
            'url' => $this->cartUrl(),
        ]));
        $result = $this->requireSuccess($workerResult);

        return AuthenticationCheck::fromPayload($result, $workerResult->diagnostics);
    }

    public function inspectCart(BrowserSession $session): CartInspection
    {
        $workerResult = $this->executor->execute($session, new WorkerCommand('inspect_cart', [
            'url' => $this->cartUrl(),
        ]));
        $result = $this->requireSuccess($workerResult);

        return CartInspection::fromPayload($result, $workerResult->diagnostics);
    }

    /** @return array<string, mixed> */
    private function requireSuccess(WorkerResult $result): array
    {
        if (! $result->ok) {
            throw new RuntimeException($result->errorMessage ?? 'The retailer browser step could not be verified.');
        }

        return $result->payload;
    }
}
