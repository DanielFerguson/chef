<?php

namespace Database\Factories;

use App\Models\AutomationRun;
use App\Models\AutomationRunItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationRunItem> */
class AutomationRunItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'automation_run_id' => AutomationRun::factory(),
            'team_id' => fn (array $attributes) => AutomationRun::query()->findOrFail((int) $attributes['automation_run_id'])->team_id,
            'position' => 1,
            'status' => 'pending',
            'requirement_snapshot' => ['name' => 'Milk', 'quantity' => 1, 'unit' => 'litre'],
        ];
    }
}
