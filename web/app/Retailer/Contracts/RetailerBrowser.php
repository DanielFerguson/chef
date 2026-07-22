<?php

namespace App\Retailer\Contracts;

use App\Models\BrowserSession;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\FulfilmentOptions;
use App\Retailer\Data\SlotSelection;
use App\Retailer\Data\SubmitResult;
use App\Retailer\Data\ToolResult;

interface RetailerBrowser
{
    public function probeAuth(BrowserSession $session): AuthCheck;

    public function inspectCart(BrowserSession $session): CartInspection;

    public function clearCart(BrowserSession $session): CartInspection;

    /**
     * @param  array{external_id?: string, name?: string, quantity?: float|int}  $product
     */
    public function addProduct(BrowserSession $session, array $product): ToolResult;

    public function extractFulfilmentOptions(BrowserSession $session, string $fulfilmentType): FulfilmentOptions;

    public function applyFulfilmentSlot(BrowserSession $session, SlotSelection $slot): ToolResult;

    public function submitOrderWithDefaultPayment(BrowserSession $session): SubmitResult;

    public function extractOrderConfirmation(BrowserSession $session): SubmitResult;
}
