<?php

namespace Database\Factories;

use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Retailer> */
class RetailerFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => fake()->unique()->slug(2),
            'website_url' => fake()->url(),
            'active' => true,
        ];
    }

    public function woolworths(): static
    {
        return $this->state([
            'name' => 'Woolworths',
            'slug' => 'woolworths',
            'website_url' => 'https://www.woolworths.com.au',
        ]);
    }
}
