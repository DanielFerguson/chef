<?php

namespace Database\Factories;

use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrowserSession> */
class BrowserSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_connection_id' => RetailerConnection::factory(),
            'team_id' => fn (array $attributes) => RetailerConnection::query()->findOrFail((int) $attributes['retailer_connection_id'])->team_id,
            'provider_session_id' => 'sess_'.fake()->uuid(),
            'purpose' => 'cart_preparation',
            'status' => 'agent_control',
            'recording_enabled' => false,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
