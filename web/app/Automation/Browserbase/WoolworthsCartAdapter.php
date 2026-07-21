<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Automation\Data\PreparedCartItem;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Models\AutomationRunItem;
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

    public function openCart(BrowserSession $session): void
    {
        $this->executor->resumeControl($session);

        try {
            $this->requireSuccess($this->executor->execute($session, new WorkerCommand('navigate', [
                'url' => $this->cartUrl(),
                'mode' => 'human_takeover',
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
        return $this->cartInspection('inspect_cart', $session);
    }

    public function clearCart(BrowserSession $session): CartInspection
    {
        return $this->cartInspection('clear_cart', $session);
    }

    public function prepareAndVerifyItem(
        BrowserSession $session,
        AutomationRunItem $item,
        CartInspection $cartBefore,
        array $preExistingLines = [],
    ): PreparedCartItem {
        $requirement = $item->requirement_snapshot;
        $identity = is_string($requirement['product_match']['product_name'] ?? null)
            ? $requirement['product_match']['product_name']
            : (string) ($requirement['name'] ?? '');
        $baseline = collect($preExistingLines)->first(function ($line) use ($identity): bool {
            return mb_strtolower(trim((string) ($line['product_name'] ?? ''))) === mb_strtolower(trim($identity));
        });
        $baselineQuantity = is_array($baseline) && is_numeric($baseline['quantity'] ?? null)
            ? (float) $baseline['quantity']
            : 0;
        $workerResult = $this->executor->execute($session, new WorkerCommand('prepare_and_verify_item', [
            'url' => $this->cartUrl(),
            'requirement' => [
                ...$requirement,
                'pre_existing_quantity' => $baselineQuantity,
            ],
            'cart_before' => [
                'line_count' => count($cartBefore->lines),
                'total' => $cartBefore->total,
            ],
        ]));
        $result = $this->requireSuccess($workerResult);

        return PreparedCartItem::fromPayload($result, $workerResult->diagnostics);
    }

    public function reconcile(BrowserSession $session): CartInspection
    {
        return $this->cartInspection('reconcile_cart', $session);
    }

    /** @return array<string, mixed> */
    private function requireSuccess(WorkerResult $result): array
    {
        if (! $result->ok) {
            throw new RuntimeException($result->errorMessage ?? 'The retailer browser step could not be verified.');
        }

        return $result->payload;
    }

    private function cartInspection(string $command, BrowserSession $session): CartInspection
    {
        $workerResult = $this->executor->execute($session, new WorkerCommand($command, [
            'url' => $this->cartUrl(),
        ]));
        $result = $this->requireSuccess($workerResult);

        return CartInspection::fromPayload($result, $workerResult->diagnostics);
    }
}
