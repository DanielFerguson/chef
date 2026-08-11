<?php

use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\ReplaceBasketJob;
use App\Jobs\RestoreBasketJob;
use App\Jobs\SelectRetailerProductsJob;
use Illuminate\Foundation\DevCommands;

test('the development command listens to every application queue', function () {
    $queueCommand = collect(DevCommands::commands())
        ->firstWhere('name', 'queue');

    expect($queueCommand)
        ->not->toBeNull()
        ->and($queueCommand['command'])->toBe(
            'php artisan queue:listen --queue=default,ai,retailer --tries=1 --timeout=0',
        );
});

test('queue leases outlast the longest retailer job timeout', function () {
    $longestRetailerTimeout = collect([
        new DiscoverRetailerProductsJob(1),
        new SelectRetailerProductsJob(1),
        new ReplaceBasketJob(1),
        new RestoreBasketJob(1),
    ])->max(fn (object $job): int => $job->timeout);

    expect($longestRetailerTimeout)->toBe(300);

    foreach (['database', 'beanstalkd', 'redis'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))
            ->toBeGreaterThan($longestRetailerTimeout);
    }
});
