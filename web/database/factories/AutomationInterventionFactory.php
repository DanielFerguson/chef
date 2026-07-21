<?php

namespace Database\Factories;

use App\Models\AutomationIntervention;
use App\Models\AutomationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AutomationIntervention> */
class AutomationInterventionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'automation_run_id' => AutomationRun::factory(),
            'team_id' => fn (array $attributes) => AutomationRun::query()->findOrFail((int) $attributes['automation_run_id'])->team_id,
            'type' => 'item_decision',
            'status' => 'pending',
            'payload' => ['message' => 'Choose how to proceed.'],
            'requested_at' => now(),
        ];
    }
}
