<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Recipes\MaterializeMealPlanRecipes;
use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Jobs\MaterializeMealPlanRecipesJob;
use App\Models\MealPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** @return array{user: User, team: Team, plan: MealPlan} */
function recipeApprovalWorkspace(int $mealCount = 2): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Recipe preparation family');
    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDays($mealCount - 1),
        'Recipe week',
    );

    foreach (range(0, $mealCount - 1) as $offset) {
        $slot = app(CreateMealSlot::class)->handle(
            $plan,
            $user,
            today()->addDays($offset),
            MealSlotKind::Dinner,
            $team->people,
        );
        app(ProposeMeal::class)->handle(
            $plan,
            $user,
            'Dinner '.($offset + 1),
            $slot,
            'A practical family dinner.',
            30,
        );
    }

    return compact('user', 'team', 'plan');
}

/**
 * @param  array<string, mixed>  $meal
 * @return array<string, mixed>
 */
function recipeApprovalDraft(array $meal): array
{
    return [
        'planned_meal_id' => $meal['planned_meal_id'],
        'title' => $meal['title'],
        'summary' => $meal['summary'],
        'servings' => $meal['servings'],
        'prep_minutes' => 10,
        'cook_minutes' => 20,
        'ingredients' => [[
            'name' => $meal['title'].' ingredients',
            'quantity' => 1,
            'unit' => 'batch',
            'preparation' => null,
            'optional' => false,
        ]],
        'steps' => [[
            'instruction' => 'Prepare and cook '.$meal['title'].'.',
            'timer_minutes' => 20,
        ]],
        'equipment' => ['Pan'],
        'notices' => [],
        'storage_guidance' => 'Refrigerate leftovers promptly.',
    ];
}

it('approves the final proposals, confirms safety, and dispatches one recipe batch idempotently', function () {
    Queue::fake();
    $workspace = recipeApprovalWorkspace();

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.approve', $workspace['plan']))
        ->assertRedirect(route('meal-plans.show', $workspace['plan']));

    $plan = $workspace['plan']->refresh();
    $safetyFingerprint = app(MealPlanSafetyContext::class)->fingerprint($plan);

    expect($plan->planning_confirmed_at)->not->toBeNull()
        ->and($plan->safety_reviewed_context_hash)->toBe($safetyFingerprint)
        ->and($plan->confirmed_safety_context_hash)->toBe($safetyFingerprint)
        ->and($plan->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($plan->proposals()->where('status', MealProposalStatus::Accepted)->count())->toBe(2)
        ->and($plan->plannedMeals()->count())->toBe(2)
        ->and(Schema::hasTable('shopping_lists'))->toBeFalse()
        ->and(Schema::hasTable('retailers'))->toBeFalse()
        ->and(Schema::hasTable('browser_sessions'))->toBeFalse();

    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);

    $this->post(route('meal-plans.approve', $plan))
        ->assertRedirect(route('meal-plans.show', $plan));

    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);
});

it('keeps recipe batches atomic and materialises a completed batch only once', function () {
    Queue::fake();
    $workspace = recipeApprovalWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft([recipeApprovalDraft($request->meals[0])]);
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Failed)
        ->and($workspace['team']->recipes()->count())->toBe(0)
        ->and($workspace['plan']->plannedMeals()->whereNotNull('recipe_version_id')->count())->toBe(0);

    app(PrepareMealPlanRecipes::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
    );
    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft(array_map(
                fn (array $meal): array => recipeApprovalDraft($meal),
                $request->meals,
            ));
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Completed)
        ->and($workspace['team']->recipes()->count())->toBe(2)
        ->and($workspace['team']->recipes()->withCount('versions')->get()->pluck('versions_count')->all())->toBe([1, 1])
        ->and($workspace['plan']->plannedMeals()->whereNotNull('recipe_version_id')->count())->toBe(2)
        ->and($workspace['plan']->derived_data_stale_at)->toBeNull()
        ->and($readiness['ready_for_safety_confirmation'])->toBeFalse()
        ->and($readiness['next_action'])->toBe('recipes_ready');
});

it('supports failed recipe retry and keeps preparation team-scoped', function () {
    Queue::fake();
    $workspace = recipeApprovalWorkspace(1);
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            throw new RuntimeException('Temporary provider failure.');
        }
    });
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Failed)
        ->and($workspace['plan']->recipe_generation_failure_message)->toBe('Chef could not prepare the completed plan’s recipes.');

    Queue::fake();
    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.recipes.prepare', $workspace['plan']))
        ->assertRedirect();

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($workspace['plan']->recipe_generation_failure_code)->toBeNull()
        ->and($workspace['plan']->recipe_generation_failure_message)->toBeNull();
    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->actingAs($outsider)
        ->post(route('meal-plans.recipes.prepare', $workspace['plan']))
        ->assertNotFound();

    expect(fn () => app(ApproveMealPlan::class)->handle($workspace['plan'], $outsider))
        ->toThrow(AuthorizationException::class);
});
