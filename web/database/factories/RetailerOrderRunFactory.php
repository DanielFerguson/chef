<?php

namespace Database\Factories;

use App\Enums\CartProductPlanStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\CartProductPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RetailerOrderRun> */
class RetailerOrderRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'team_id' => fn (array $attributes) => ShoppingList::query()->findOrFail((int) $attributes['shopping_list_id'])->team_id,
            'shopping_list_revision_id' => fn (array $attributes) => ShoppingListRevision::factory()->create([
                'shopping_list_id' => $attributes['shopping_list_id'],
                'team_id' => $attributes['team_id'],
            ])->id,
            'retailer_connection_id' => fn (array $attributes) => RetailerConnection::factory()->create([
                'team_id' => $attributes['team_id'],
            ])->id,
            'started_by_user_id' => User::factory(),
            'cart_product_plan_id' => fn (array $attributes) => CartProductPlan::query()->create([
                'team_id' => $attributes['team_id'],
                'shopping_list_id' => $attributes['shopping_list_id'],
                'shopping_list_revision_id' => $attributes['shopping_list_revision_id'],
                'retailer_id' => RetailerConnection::query()->findOrFail((int) $attributes['retailer_connection_id'])->retailer_id,
                'status' => CartProductPlanStatus::Ready,
                'input_checksum' => hash('sha256', 'factory-input'),
                'safety_fingerprint' => hash('sha256', 'factory-safety'),
            ])->id,
            'status' => RetailerOrderRunStatus::Draft,
            'fulfilment_type' => null,
            'fulfilment_options' => null,
            'fulfilment_options_expires_at' => null,
            'selected_slot' => null,
            'confirmation' => null,
            'cart_checksum' => null,
            'confirmation_fingerprint' => null,
            'retailer_order_reference' => null,
            'failure_message' => null,
            'limits' => ['max_runtime_seconds' => 180],
            'expires_at' => now()->addHour(),
        ];
    }
}
