<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Automation\Data\ItemPreparationResult;
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
        $this->requireSuccess($this->executor->execute($session, new WorkerCommand('navigate', [
            'url' => $this->loginUrl(),
            'mode' => 'human_login',
        ])));
    }

    public function openCart(BrowserSession $session): void
    {
        $this->requireSuccess($this->executor->execute($session, new WorkerCommand('navigate', [
            'url' => $this->cartUrl(),
            'mode' => 'human_takeover',
        ])));
    }

    public function checkAuthentication(BrowserSession $session): AuthenticationCheck
    {
        $result = $this->requireSuccess($this->executor->execute($session, new WorkerCommand('probe_authentication', [
            'url' => $this->cartUrl(),
        ])));

        return AuthenticationCheck::fromPayload($result);
    }

    public function inspectCart(BrowserSession $session): CartInspection
    {
        return $this->cartInspection('inspect_cart', $session);
    }

    public function clearCart(BrowserSession $session): CartInspection
    {
        return $this->cartInspection('clear_cart', $session);
    }

    public function prepareItem(
        BrowserSession $session,
        AutomationRunItem $item,
        CartInspection $cartBefore,
        array $preExistingLines = [],
    ): ItemPreparationResult {
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
        $result = $this->requireSuccess($this->executor->execute($session, new WorkerCommand('prepare_item', [
            'requirement' => [
                ...$requirement,
                'pre_existing_quantity' => $baselineQuantity,
            ],
            'cart_before' => [
                'line_count' => count($cartBefore->lines),
                'total' => $cartBefore->total,
            ],
        ])));

        return ItemPreparationResult::fromPayload($result);
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
        $result = $this->requireSuccess($this->executor->execute($session, new WorkerCommand($command, [
            'url' => $this->cartUrl(),
        ])));

        return CartInspection::fromPayload($result);
    }
}
