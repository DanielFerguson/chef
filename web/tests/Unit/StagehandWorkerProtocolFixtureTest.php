<?php

use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;

it('keeps recorded Stagehand worker observations compatible with the retailer browser protocol', function () {
    $fixture = json_decode(
        file_get_contents(dirname(__DIR__).'/Fixtures/Automation/stagehand-worker-protocol.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($fixture['version'])->toBe('chef.retailer.stagehand.v1')
        ->and($fixture['cases'])->toHaveCount(6);

    foreach ($fixture['cases'] as $case) {
        expect($case['request']['version'])->toBe('chef.retailer.stagehand.v1')
            ->and($case['request']['command'])->toBe($case['command']);

        match ($case['command']) {
            'probe_auth' => expect(AuthCheck::fromPayload($case['response']))
                ->toBeInstanceOf(AuthCheck::class)
                ->authenticated->toBeFalse()
                ->reason->not->toBe(''),
            'inspect_cart', 'clear_cart' => expect(CartInspection::fromPayload($case['response']))
                ->toBeInstanceOf(CartInspection::class)
                ->lines->toBe([])
                ->currency->toBe('AUD'),
            'add_product' => expect($case['response'])
                ->toHaveKey('verified')
                ->and($case['response']['verified'])->toBeTrue()
                ->and($case['response']['status'])->toBe('matched')
                ->and($case['response']['product']['external_id'])->toBe('123456')
                ->and(CartInspection::fromPayload($case['response']['cart']))
                ->toBeInstanceOf(CartInspection::class)
                ->lines->toHaveCount(1),
            default => throw new LogicException('Unexpected Stagehand fixture command.'),
        };
    }
});
