<?php

namespace Database\Factories;

use App\Enums\RetailerOrderRunItemStatus;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RetailerOrderRunItem> */
class RetailerOrderRunItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_order_run_id' => RetailerOrderRun::factory(),
            'team_id' => fn (array $attributes) => RetailerOrderRun::query()->findOrFail((int) $attributes['retailer_order_run_id'])->team_id,
            'position' => 1,
            'status' => RetailerOrderRunItemStatus::Pending,
            'requirement_snapshot' => ['name' => 'Milk', 'quantity' => 1, 'unit' => 'litre'],
            'matched_product' => null,
            'attempts' => 0,
            'failure_message' => null,
            'resolved_at' => null,
        ];
    }
}
