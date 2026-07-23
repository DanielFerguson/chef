<?php

namespace App\Ai\Tools;

use App\Actions\Planning\ProposeMeal;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateMealProposal implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly Message $message,
        private readonly ProposeMeal $proposeMeal,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a visible plan-specific meal suggestion, including meals requested for this week. When a meal_slot_id is provided, this replaces any prior pending proposal for that slot. A person must accept it before it becomes selected.';
    }

    public function handle(Request $request): Stringable|string
    {
        $slotId = $request->integer('meal_slot_id');
        $slot = $slotId > 0 ? MealSlot::query()->findOrFail($slotId) : null;
        $minutes = $request->integer('estimated_minutes');

        $proposal = $this->proposeMeal->handle(
            mealPlan: $this->mealPlan,
            user: $this->actor,
            title: $request->string('title')->toString(),
            mealSlot: $slot,
            summary: $request->string('summary')->toString() ?: null,
            estimatedMinutes: $minutes > 0 ? $minutes : null,
            estimatedCost: $request->float('estimated_cost') ?: null,
            message: $this->message,
        );

        return $proposal->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Concise meal name.')->required(),
            'meal_slot_id' => $schema->integer()->description('Target meal slot identifier, when known.'),
            'summary' => $schema->string()->description('Short practical description.'),
            'estimated_minutes' => $schema->integer()->description('Estimated total preparation and cooking minutes.'),
            'estimated_cost' => $schema->number()->description('Estimated total household cost in local currency.'),
        ];
    }
}
