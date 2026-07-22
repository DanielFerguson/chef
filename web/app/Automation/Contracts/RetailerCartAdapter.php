<?php

namespace App\Automation\Contracts;

use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Models\BrowserSession;

interface RetailerCartAdapter
{
    public function retailerSlug(): string;

    public function loginUrl(): string;

    public function cartUrl(): string;

    public function openLogin(BrowserSession $session): void;

    public function checkAuthentication(BrowserSession $session): AuthenticationCheck;

    public function inspectCart(BrowserSession $session): CartInspection;
}
