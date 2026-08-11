<?php

use App\Enums\RetailerWorkerCommand;
use App\Retailer\Testing\FakeRetailerAutomationGateway;

it('replays the recorded retailer fixture for a dynamic grocery requirement', function () {
    config()->set('retailer.testing.recorded_fixture', 'tests/Fixtures/Retailer/happy-path.json');
    $gateway = new FakeRetailerAutomationGateway;

    $result = $gateway->execute('recorded-context', RetailerWorkerCommand::SearchProducts, [
        'mode' => 'revalidate',
        'requirements' => [[
            'requirement_id' => 42,
            'exact_sku' => 'recorded-satay-500',
            'queries' => ['recorded-satay-500'],
        ]],
    ]);

    expect($result->succeeded())->toBeTrue()
        ->and($result->data['candidates'])->toHaveCount(1)
        ->and($result->data['candidates'][0]['requirement_id'])->toBe(42)
        ->and($result->data['candidates'][0]['sku'])->toBe('recorded-satay-500')
        ->and($gateway->basketLines[0]['sku'])->toBe('existing-apples');
});
