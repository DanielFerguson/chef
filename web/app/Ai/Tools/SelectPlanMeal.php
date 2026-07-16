<?php

namespace App\Ai\Tools;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\SelectPlannedMeal;
use App\Enums\PlannedMealType;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SelectPlanMeal implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly SelectPlannedMeal $selectMeal,
        private readonly AssessMealPlanReadiness $assessReadiness,
    ) {}

    public function description(): Stringable|string
    {
        return 'Select a recipe, custom meal, leftovers, takeaway, eating-out meal, or intentionally open meal for a slot after the user chooses it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $slot = MealSlot::query()->where('meal_plan_id', $this->mealPlan->id)->findOrFail($request->integer('meal_slot_id'));
        $recipeVersionId = $request->integer('recipe_version_id');
        $sourceMealId = $request->integer('source_planned_meal_id');
        $recipeVersion = $recipeVersionId > 0
            ? RecipeVersion::query()->where('team_id', $this->mealPlan->team_id)->findOrFail($recipeVersionId)
            : null;
        $sourceMeal = $sourceMealId > 0
            ? PlannedMeal::query()->where('meal_plan_id', $this->mealPlan->id)->findOrFail($sourceMealId)
            : null;
        $servings = $request->float('servings');
        $estimatedMinutes = $request->integer('estimated_minutes');

        $plannedMeal = $this->selectMeal->handle(
            $slot,
            $this->actor,
            PlannedMealType::from($request->string('type')->toString()),
            $recipeVersion,
            $request->string('title')->toString() ?: null,
            $request->string('summary')->toString() ?: null,
            $servings > 0 ? $servings : null,
            $estimatedMinutes > 0 ? $estimatedMinutes : null,
            $request->float('estimated_cost') ?: null,
            $sourceMeal,
        );

        return json_encode([
            'planned_meal' => $plannedMeal,
            'plan_progress' => $this->assessReadiness->handle($this->mealPlan->refresh()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_slot_id' => $schema->integer()->description('Target slot identifier from InspectMealPlan.')->required(),
            'type' => $schema->string()->description('recipe, custom, leftovers, takeaway, eating_out, or open.')->required(),
            'recipe_version_id' => $schema->integer()->description('Exact recipe version identifier when type is recipe.'),
            'source_planned_meal_id' => $schema->integer()->description('Original planned meal identifier when type is leftovers.'),
            'title' => $schema->string()->description('Meal name for non-recipe types.'),
            'summary' => $schema->string()->description('Optional short description.'),
            'servings' => $schema->number()->description('Total servings for this meal.'),
            'estimated_minutes' => $schema->integer()->description('Estimated total minutes for non-recipe meals.'),
            'estimated_cost' => $schema->number()->description('Estimated family cost in local currency.'),
        ];
    }
}
