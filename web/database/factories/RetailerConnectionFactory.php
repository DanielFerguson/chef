<?php

namespace Database\Factories;

use App\Models\Retailer;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RetailerConnection> */
class RetailerConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'retailer_id' => Retailer::factory(),
            'owner_user_id' => User::factory(),
            'provider' => 'browserbase',
            'provider_context_id' => 'ctx_'.fake()->uuid(),
            'status' => 'connected',
            'last_verified_at' => now(),
        ];
    }
}
