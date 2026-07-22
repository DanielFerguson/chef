<?php

namespace App\Retailer\Testing;

use App\Models\BrowserSession;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\FulfilmentOptions;
use App\Retailer\Data\SlotSelection;
use App\Retailer\Data\SubmitResult;
use App\Retailer\Data\ToolResult;
use RuntimeException;

class FakeRetailerBrowser implements RetailerBrowser
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $calls = [];

    /**
     * @var array<string, list<mixed>>
     */
    private array $queued = [];

    public function queue(string $method, mixed $result): self
    {
        $this->queued[$method][] = $result;

        return $this;
    }

    public function probeAuth(BrowserSession $session): AuthCheck
    {
        $this->record('probeAuth', ['session_id' => $session->id]);

        return $this->next(
            'probeAuth',
            new AuthCheck(authenticated: true, reason: 'Fake auth probe passed.'),
        );
    }

    public function inspectCart(BrowserSession $session): CartInspection
    {
        $this->record('inspectCart', ['session_id' => $session->id]);

        return $this->next(
            'inspectCart',
            new CartInspection(lines: [], total: 0.0),
        );
    }

    public function clearCart(BrowserSession $session): CartInspection
    {
        $this->record('clearCart', ['session_id' => $session->id]);

        return $this->next(
            'clearCart',
            new CartInspection(lines: [], total: 0.0),
        );
    }

    public function addProduct(BrowserSession $session, array $product): ToolResult
    {
        $this->record('addProduct', [
            'session_id' => $session->id,
            'product' => $product,
        ]);

        return $this->next(
            'addProduct',
            new ToolResult(ok: true, payload: $product),
        );
    }

    public function extractFulfilmentOptions(BrowserSession $session, string $fulfilmentType): FulfilmentOptions
    {
        $this->record('extractFulfilmentOptions', [
            'session_id' => $session->id,
            'fulfilment_type' => $fulfilmentType,
        ]);

        return $this->next(
            'extractFulfilmentOptions',
            new FulfilmentOptions(type: $fulfilmentType, slots: []),
        );
    }

    public function applyFulfilmentSlot(BrowserSession $session, SlotSelection $slot): ToolResult
    {
        $this->record('applyFulfilmentSlot', [
            'session_id' => $session->id,
            'slot' => $slot,
        ]);

        return $this->next(
            'applyFulfilmentSlot',
            new ToolResult(ok: true, payload: ['slot_id' => $slot->id]),
        );
    }

    public function submitOrderWithDefaultPayment(BrowserSession $session): SubmitResult
    {
        $this->record('submitOrderWithDefaultPayment', ['session_id' => $session->id]);

        return $this->next(
            'submitOrderWithDefaultPayment',
            new SubmitResult(ok: true),
        );
    }

    public function extractOrderConfirmation(BrowserSession $session): SubmitResult
    {
        $this->record('extractOrderConfirmation', ['session_id' => $session->id]);

        return $this->next(
            'extractOrderConfirmation',
            new SubmitResult(ok: true, retailerOrderReference: 'FAKE-ORDER', confirmationText: 'Fake order confirmed'),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $method, array $context = []): void
    {
        $this->calls[] = ['method' => $method, ...$context];
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    private function next(string $method, mixed $default): mixed
    {
        if (($this->queued[$method] ?? []) === []) {
            return $default;
        }

        $result = array_shift($this->queued[$method]);

        if (! is_a($result, $default::class)) {
            throw new RuntimeException("Queued {$method} response must be an instance of ".$default::class);
        }

        return $result;
    }
}
