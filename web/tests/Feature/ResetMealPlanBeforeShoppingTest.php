<?php

use App\Actions\Debug\ResetMealPlanBeforeShopping;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\CartProductPlanStatus;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListGenerationStatus;
use App\Models\CartProductPlan;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('resets a meal plan to finished planning without shopping preparation', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Reset family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Satay chicken',
        'A quick dinner.',
        2,
        10,
        20,
        [['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g']],
        [['instruction' => 'Cook everything together.']],
    );
    $planned = app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Recipe,
        $recipe->latestVersion,
        title: 'Satay chicken',
    );

    $list = ShoppingList::factory()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $user->id,
        'generation_status' => ShoppingListGenerationStatus::Ready,
    ]);
    $revision = ShoppingListRevision::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'user_id' => $user->id,
        'revision' => 1,
        'snapshot' => ['items' => []],
    ]);
    $retailer = Retailer::query()->firstOrCreate(
        ['slug' => 'woolworths'],
        ['name' => 'Woolworths', 'active' => true],
    );
    CartProductPlan::query()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'shopping_list_revision_id' => $revision->id,
        'retailer_id' => $retailer->id,
        'status' => CartProductPlanStatus::NeedsReview,
        'input_checksum' => hash('sha256', 'reset-test'),
        'safety_fingerprint' => hash('sha256', 'safety'),
        'snapshot' => ['version' => 'test'],
    ]);

    $plan->update([
        'planning_confirmed_at' => now(),
        'shopping_approved_by_user_id' => $user->id,
        'shopping_approved_at' => now(),
        'shopping_approval_fingerprint' => hash('sha256', 'approved'),
        'confirmed_safety_context_hash' => hash('sha256', 'confirmed'),
        'safety_reviewed_at' => now(),
        'safety_reviewed_by_user_id' => $user->id,
        'safety_reviewed_context_hash' => hash('sha256', 'reviewed'),
        'recipe_generation_status' => MealPlanRecipeGenerationStatus::Failed,
        'recipe_generation_attempts' => 2,
        'recipe_generation_failure_code' => 'request_exception',
        'recipe_generation_failure_message' => 'Chef could not prepare the completed plan’s recipes.',
    ]);

    $summary = app(ResetMealPlanBeforeShopping::class)->handle($plan->refresh());

    expect($summary['deleted_shopping_lists'])->toBe(1)
        ->and($summary['deleted_cart_product_plans'])->toBe(1)
        ->and($summary['detached_recipes'])->toBe(1)
        ->and(ShoppingList::query()->where('meal_plan_id', $plan->id)->exists())->toBeFalse()
        ->and($planned->refresh()->recipe_version_id)->toBeNull()
        ->and($planned->type)->toBe(PlannedMealType::Custom)
        ->and($plan->refresh()->planning_confirmed_at)->toBeNull()
        ->and($plan->shopping_approved_at)->toBeNull()
        ->and($plan->recipe_generation_status)->toBeNull()
        ->and($plan->safety_reviewed_at)->not->toBeNull()
        ->and(app(AssessMealPlanReadiness::class)->handle($plan->refresh())['ready_for_approval'])->toBeTrue();
});

it('keeps recipe versions when requested', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Keep recipes family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Satay chicken',
        'A quick dinner.',
        2,
        10,
        20,
        [['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g']],
        [['instruction' => 'Cook everything together.']],
    );
    $planned = app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Recipe,
        $recipe->latestVersion,
        title: 'Satay chicken',
    );
    $plan->update([
        'planning_confirmed_at' => now(),
        'shopping_approved_at' => now(),
        'shopping_approved_by_user_id' => $user->id,
    ]);

    app(ResetMealPlanBeforeShopping::class)->handle($plan->refresh(), keepRecipes: true);

    expect($planned->refresh()->recipe_version_id)->toBe($recipe->latestVersion->id)
        ->and($planned->type)->toBe(PlannedMealType::Recipe)
        ->and($plan->refresh()->planning_confirmed_at)->toBeNull();
});

it('exposes the reset through an artisan command', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Command family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $plan->update([
        'planning_confirmed_at' => now(),
        'shopping_approved_at' => now(),
        'shopping_approved_by_user_id' => $user->id,
        'recipe_generation_status' => MealPlanRecipeGenerationStatus::Completed,
    ]);

    $this->artisan('chef:meal-plan:reset-before-shopping', [
        'mealPlan' => $plan->id,
    ])->expectsOutputToContain('reset to finished planning')->assertSuccessful();

    expect($plan->refresh()->shopping_approved_at)->toBeNull()
        ->and($plan->recipe_generation_status)->toBeNull();
});

it('refuses the reset outside local and testing environments', function () {
    $this->app['env'] = 'production';
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Prod family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());

    expect(fn () => app(ResetMealPlanBeforeShopping::class)->handle($plan))
        ->toThrow(RuntimeException::class, 'only available in local and testing');
});
