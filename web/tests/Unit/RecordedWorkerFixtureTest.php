<?php

use App\Automation\Data\AuthenticationCheck;
use App\Automation\Data\CartInspection;
use App\Automation\Data\ItemPreparationResult;

it('keeps recorded worker observations compatible with the typed automation protocol', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Automation/worker-protocol.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($fixture['version'])->toBe('chef.browser.v1')
        ->and($fixture['cases'])->toHaveCount(13);

    foreach ($fixture['cases'] as $case) {
        match ($case['command']) {
            'probe_authentication' => expect(AuthenticationCheck::fromPayload($case['response']))
                ->toBeInstanceOf(AuthenticationCheck::class),
            'inspect_cart', 'reconcile_cart' => expect(CartInspection::fromPayload($case['response']))
                ->toBeInstanceOf(CartInspection::class),
            'prepare_item' => expect(ItemPreparationResult::fromPayload($case['response']))
                ->toBeInstanceOf(ItemPreparationResult::class),
            'capture' => expect(
                (bool) ($case['response']['bot_detected'] ?? false)
                || (bool) ($case['response']['sensitive_screen'] ?? false),
            )->toBeTrue(),
            'execute_action' => expect($case['response']['verified'] ?? false)->toBeTrue(),
            default => throw new LogicException('Unexpected fixture command.'),
        };
    }
});
