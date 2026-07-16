<?php

namespace App\Ai\Tools;

use App\Actions\Planning\MovePlannedMeal;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MoveSelectedMeal implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly MovePlannedMeal $movePlannedMeal,
    ) {}

    public function description(): Stringable|string
    {
        return 'Move an already selected meal to an open slot in the same plan after the user asks for that change.';
    }

    public function handle(Request $request): Stringable|string
    {
        $meal = PlannedMeal::query()
            ->where('meal_plan_id', $this->mealPlan->id)
            ->findOrFail($request->integer('planned_meal_id'));
        $target = MealSlot::query()
            ->where('meal_plan_id', $this->mealPlan->id)
            ->findOrFail($request->integer('target_meal_slot_id'));

        return $this->movePlannedMeal->handle($meal, $target, $this->actor)->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'planned_meal_id' => $schema->integer()->description('Selected meal identifier.')->required(),
            'target_meal_slot_id' => $schema->integer()->description('Open target slot identifier.')->required(),
        ];
    }
}
