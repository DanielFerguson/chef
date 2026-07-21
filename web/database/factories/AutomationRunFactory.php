<?php

namespace Database\Factories;

use App\Models\AutomationRun;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationRun> */
class AutomationRunFactory extends Factory
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
            'status' => 'checking_connection',
            'idempotency_key' => fake()->uuid(),
            'frozen_snapshot' => ['revision' => 1, 'items' => []],
            'frozen_snapshot_checksum' => hash('sha256', '[]'),
            'limits' => ['max_actions' => 40, 'max_runtime_seconds' => 180],
            'expires_at' => now()->addHour(),
        ];
    }
}
