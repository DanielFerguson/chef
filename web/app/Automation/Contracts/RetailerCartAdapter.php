<?php

namespace App\Automation\Contracts;

use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Automation\Data\ItemPreparationResult;
use App\Models\AutomationRunItem;
use App\Models\BrowserSession;

interface RetailerCartAdapter
{
    public function retailerSlug(): string;

    public function loginUrl(): string;

    public function cartUrl(): string;

    public function openLogin(BrowserSession $session): void;

    public function openCart(BrowserSession $session): void;

    public function checkAuthentication(BrowserSession $session): AuthenticationCheck;

    public function inspectCart(BrowserSession $session): CartInspection;

    public function clearCart(BrowserSession $session): CartInspection;

    /** @param array<int, array<string, mixed>> $preExistingLines */
    public function prepareItem(
        BrowserSession $session,
        AutomationRunItem $item,
        CartInspection $cartBefore,
        array $preExistingLines = [],
    ): ItemPreparationResult;

    public function reconcile(BrowserSession $session): CartInspection;
}
