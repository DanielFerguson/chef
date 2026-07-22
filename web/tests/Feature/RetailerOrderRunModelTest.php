<?php

use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerOrderRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a team-scoped retailer order run with fulfilment and confirmation columns', function () {
    $expiresAt = now()->addHour();

    $run = RetailerOrderRun::factory()->create([
        'status' => RetailerOrderRunStatus::Draft,
        'fulfilment_type' => null,
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
        'selected_slot' => null,
        'confirmation' => null,
        'cart_checksum' => null,
        'confirmation_fingerprint' => null,
        'retailer_order_reference' => null,
        'expires_at' => $expiresAt,
    ]);

    expect($run->team_id)->toBe($run->shoppingList->team_id)
        ->and($run->shopping_list_id)->toBeInt()
        ->and($run->shopping_list_revision_id)->toBeInt()
        ->and($run->retailer_connection_id)->toBeInt()
        ->and($run->started_by_user_id)->toBeInt()
        ->and($run->cart_product_plan_id)->toBeInt()
        ->and($run->status)->toBe(RetailerOrderRunStatus::Draft)
        ->and($run->fulfilment_type)->toBeNull()
        ->and($run->fulfilment_options)->toBeNull()
        ->and($run->fulfilment_options_expires_at)->toBeNull()
        ->and($run->selected_slot)->toBeNull()
        ->and($run->confirmation)->toBeNull()
        ->and($run->cart_checksum)->toBeNull()
        ->and($run->confirmation_fingerprint)->toBeNull()
        ->and($run->retailer_order_reference)->toBeNull()
        ->and($run->expires_at?->timestamp)->toBe($expiresAt->timestamp)
        ->and($run->shoppingListRevision->team_id)->toBe($run->team_id)
        ->and($run->retailerConnection->team_id)->toBe($run->team_id)
        ->and($run->cartProductPlan->team_id)->toBe($run->team_id)
        ->and($run->starter->id)->toBe($run->started_by_user_id);
});
