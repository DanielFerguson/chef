<?php

namespace Database\Factories;

use App\Enums\AutomationPolicyDecision;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RetailerOrderStep> */
class RetailerOrderStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_order_run_id' => RetailerOrderRun::factory(),
            'team_id' => fn (array $attributes) => RetailerOrderRun::query()->findOrFail((int) $attributes['retailer_order_run_id'])->team_id,
            'retailer_order_run_item_id' => null,
            'browser_session_id' => null,
            'sequence' => 1,
            'action_type' => 'inspect_cart',
            'policy_decision' => AutomationPolicyDecision::Allowed,
            'input_summary' => ['scope' => 'cart'],
            'output_summary' => ['line_count' => 0],
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
