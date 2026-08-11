<?php

namespace Database\Factories;

use App\Models\RetailerPurchasePolicy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetailerPurchasePolicy>
 */
class RetailerPurchasePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => 'coles',
            'home_brand_preference' => 'allow',
            'bulk_preference' => 'avoid',
            'organic_preference' => 'no_preference',
            'preferred_brands' => [],
            'default_basket_target_cents' => null,
        ];
    }
}
