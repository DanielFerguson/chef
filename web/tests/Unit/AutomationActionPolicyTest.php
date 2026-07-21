<?php

use App\Automation\Policy\AutomationActionPolicy;
use App\Enums\AutomationPolicyDecision;

it('allows only safe actions on Woolworths cart surfaces', function () {
    $policy = new AutomationActionPolicy;

    expect($policy->assess(
        ['type' => 'click', 'x' => 100, 'y' => 200],
        ['url' => 'https://www.woolworths.com.au/shop/checkout/cart'],
    )->decision)->toBe(AutomationPolicyDecision::Allowed)
        ->and($policy->assess(
            ['type' => 'scroll', 'scroll_y' => 500],
            ['url' => 'https://www.woolworths.com.au/shop/search/products?searchTerm=milk'],
        )->decision)->toBe(AutomationPolicyDecision::Allowed);
});

it('blocks forbidden origins actions keys and sensitive fields', function () {
    $policy = new AutomationActionPolicy;

    expect($policy->assess(
        ['type' => 'click'],
        ['url' => 'https://example.com'],
    )->decision)->toBe(AutomationPolicyDecision::Blocked)
        ->and($policy->assess(
            ['type' => 'drag'],
            ['url' => 'https://www.woolworths.com.au/shop/search/products'],
        )->decision)->toBe(AutomationPolicyDecision::Blocked)
        ->and($policy->assess(
            ['type' => 'keypress', 'keys' => ['CTRL', 'L']],
            ['url' => 'https://www.woolworths.com.au/shop/search/products'],
        )->decision)->toBe(AutomationPolicyDecision::Blocked)
        ->and($policy->assess(
            ['type' => 'type', 'text' => 'secret'],
            [
                'url' => 'https://www.woolworths.com.au/shop/search/products',
                'sensitive_field' => true,
            ],
        )->decision)->toBe(AutomationPolicyDecision::Blocked);
});

it('requires intervention on checkout account captcha and sensitive screens', function () {
    $policy = new AutomationActionPolicy;

    expect($policy->assess(
        ['type' => 'click'],
        ['url' => 'https://www.woolworths.com.au/shop/checkout/payment'],
    )->decision)->toBe(AutomationPolicyDecision::RequiresIntervention)
        ->and($policy->assess(
            ['type' => 'click'],
            [
                'url' => 'https://www.woolworths.com.au/shop/search/products',
                'bot_detected' => true,
            ],
        )->decision)->toBe(AutomationPolicyDecision::RequiresIntervention)
        ->and($policy->assess(
            ['type' => 'click'],
            [
                'url' => 'https://www.woolworths.com.au/shop/search/products',
                'sensitive_screen' => true,
            ],
        )->decision)->toBe(AutomationPolicyDecision::RequiresIntervention);
});
