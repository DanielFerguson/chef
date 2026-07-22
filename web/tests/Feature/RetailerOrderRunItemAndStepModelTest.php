<?php

use App\Enums\AutomationPolicyDecision;
use App\Enums\RetailerOrderRunItemStatus;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\RetailerOrderStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('persists retailer order run items with outcome statuses and snapshots', function (string $statusValue) {
    $status = RetailerOrderRunItemStatus::from($statusValue);
    $run = RetailerOrderRun::factory()->create();
    $resolvedAt = $status->isResolved() ? now() : null;

    $item = RetailerOrderRunItem::factory()->create([
        'retailer_order_run_id' => $run->id,
        'team_id' => $run->team_id,
        'position' => 1,
        'status' => $status,
        'requirement_snapshot' => ['name' => 'Milk', 'quantity' => 1, 'unit' => 'litre'],
        'matched_product' => $status === RetailerOrderRunItemStatus::Matched
            ? ['external_id' => 'sku-1', 'name' => 'Full cream milk']
            : null,
        'attempts' => 1,
        'failure_message' => $status === RetailerOrderRunItemStatus::Failed ? 'Could not match product' : null,
        'resolved_at' => $resolvedAt,
    ]);

    expect($item->team_id)->toBe($run->team_id)
        ->and($item->retailer_order_run_id)->toBe($run->id)
        ->and($item->shopping_list_item_id)->toBeNull()
        ->and($item->position)->toBe(1)
        ->and($item->status)->toBe($status)
        ->and($item->requirement_snapshot)->toBe(['name' => 'Milk', 'quantity' => 1, 'unit' => 'litre'])
        ->and($item->attempts)->toBe(1)
        ->and($run->items()->sole()->id)->toBe($item->id)
        ->and($item->run->id)->toBe($run->id);
})->with([
    'pending',
    'searching',
    'matched',
    'substituted',
    'unavailable',
    'skipped',
    'awaiting_decision',
    'failed',
]);

it('appends redacted audit steps without screenshot or credential columns', function () {
    $run = RetailerOrderRun::factory()->create();
    $item = RetailerOrderRunItem::factory()->create([
        'retailer_order_run_id' => $run->id,
        'team_id' => $run->team_id,
        'position' => 1,
    ]);

    expect(Schema::getColumnListing('retailer_order_steps'))->not->toContain('screenshot')
        ->and(Schema::getColumnListing('retailer_order_steps'))->not->toContain('screenshot_path')
        ->and(Schema::getColumnListing('retailer_order_steps'))->not->toContain('credentials')
        ->and(Schema::getColumnListing('retailer_order_steps'))->not->toContain('password')
        ->and(Schema::getColumnListing('retailer_order_steps'))->not->toContain('cookie');

    $first = RetailerOrderStep::query()->create([
        'team_id' => $run->team_id,
        'retailer_order_run_id' => $run->id,
        'retailer_order_run_item_id' => $item->id,
        'browser_session_id' => null,
        'sequence' => 1,
        'action_type' => 'search_product',
        'policy_decision' => AutomationPolicyDecision::Allowed,
        'input_summary' => ['query' => 'milk'],
        'output_summary' => ['matches' => 3],
        'started_at' => now()->subSeconds(5),
        'completed_at' => now()->subSeconds(3),
    ]);

    $second = RetailerOrderStep::query()->create([
        'team_id' => $run->team_id,
        'retailer_order_run_id' => $run->id,
        'retailer_order_run_item_id' => null,
        'browser_session_id' => null,
        'sequence' => 2,
        'action_type' => 'inspect_cart',
        'policy_decision' => AutomationPolicyDecision::Allowed,
        'input_summary' => ['scope' => 'cart'],
        'output_summary' => ['line_count' => 1],
        'started_at' => now()->subSeconds(2),
        'completed_at' => now(),
    ]);

    expect($run->steps()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->fresh()->policy_decision)->toBe(AutomationPolicyDecision::Allowed)
        ->and($first->fresh()->input_summary)->toBe(['query' => 'milk'])
        ->and($first->fresh()->output_summary)->toBe(['matches' => 3])
        ->and($first->runItem->id)->toBe($item->id)
        ->and($second->runItem)->toBeNull()
        ->and($item->steps()->sole()->id)->toBe($first->id);
});
