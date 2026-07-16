<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\MovePlannedMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Planning\UpdateMealSlotParticipants;
use App\Actions\Planning\UpdatePlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Recipes\CreateRecipeVersion;
use App\Actions\Recipes\ImportRecipeText;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\ConstraintKind;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

function m3PlanningWorkspace(int $days = 13): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'M3 family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays($days));

    return compact('user', 'team', 'plan');
}

function m3CreateRecipe(array $workspace, string $title = 'Satay chicken')
{
    return app(CreateRecipe::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $title,
        'A quick family dinner.',
        2,
        15,
        20,
        [
            ['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g'],
            ['name' => 'Peanut butter', 'quantity' => 2, 'unit' => 'tbsp'],
        ],
        [
            ['instruction' => 'Slice the chicken.'],
            ['instruction' => 'Cook with the sauce.', 'timer_minutes' => 12],
        ],
        ['Frying pan'],
        [['kind' => 'marinate', 'instruction' => 'Marinate the chicken.', 'lead_minutes' => 30]],
    );
}

it('creates complete immutable recipe versions', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $versionOne = $recipe->latestVersion;

    expect($versionOne->version)->toBe(1)
        ->and($versionOne->ingredients)->toHaveCount(2)
        ->and($versionOne->steps)->toHaveCount(2)
        ->and($versionOne->equipment)->toHaveCount(1)
        ->and($versionOne->preparationNotices)->toHaveCount(1)
        ->and($workspace['team']->recipes()->sole()->is($recipe))->toBeTrue();

    $versionTwo = app(CreateRecipeVersion::class)->handle(
        $recipe,
        $workspace['user'],
        'Satay chicken, mild',
        'A milder revision.',
        4,
        10,
        20,
        [['name' => 'Chicken thigh', 'quantity' => 1, 'unit' => 'kg']],
        [['instruction' => 'Cook until golden.']],
    );

    expect($versionTwo->version)->toBe(2)
        ->and($versionOne->fresh()->title)->toBe('Satay chicken')
        ->and($recipe->refresh()->title)->toBe('Satay chicken, mild');
});

it('imports a minimally structured text recipe without network access', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = app(ImportRecipeText::class)->handle($workspace['team'], $workspace['user'], <<<'TEXT'
Pork Katsu

Ingredients
- Pork loin
- Panko crumbs

Steps
1. Crumb the pork.
2. Air fry until golden.
TEXT, 'https://example.test/pork-katsu');

    expect($recipe->title)->toBe('Pork Katsu')
        ->and($recipe->latestVersion->ingredients->pluck('name')->all())->toBe(['Pork loin', 'Panko crumbs'])
        ->and($recipe->latestVersion->steps)->toHaveCount(2)
        ->and($recipe->source_url)->toBe('https://example.test/pork-katsu');
});

it('keeps recipes and recipe versions inside their family boundary', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect($workspace['user']->can('view', $recipe))->toBeTrue()
        ->and($workspace['user']->can('update', $recipe))->toBeTrue()
        ->and($outsider->can('view', $recipe))->toBeFalse();

    app(CreateRecipeVersion::class)->handle(
        $recipe,
        $outsider,
        'Stolen recipe',
        null,
        2,
        null,
        null,
        [['name' => 'Unknown']],
        [['instruction' => 'No.']],
    );
})->throws(AuthorizationException::class);

it('plans recipes and preserves the exact version selected', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $versionOne = $recipe->latestVersion;
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Recipe, $versionOne);

    app(CreateRecipeVersion::class)->handle(
        $recipe,
        $workspace['user'],
        'Satay chicken v2',
        null,
        2,
        null,
        null,
        [['name' => 'Chicken']],
        [['instruction' => 'Cook it differently.']],
    );

    expect($planned->refresh()->recipe_version_id)->toBe($versionOne->id)
        ->and($planned->recipeVersion->title)->toBe('Satay chicken')
        ->and(fn () => $versionOne->delete())->toThrow(QueryException::class);
});

it('supports custom meal states and participant serving overrides per slot', function () {
    $workspace = m3PlanningWorkspace();
    $secondPerson = $workspace['team']->people()->create(['name' => 'Tahlia', 'created_by_user_id' => $workspace['user']->id]);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Snack, $workspace['team']->people);

    app(UpdateMealSlotParticipants::class)->handle($slot, $workspace['user'], [
        $workspace['team']->people()->oldest('id')->firstOrFail()->id => 1.5,
        $secondPerson->id => 0.75,
    ]);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Takeaway, title: 'Friday takeaway', servings: 2.25);
    app(UpdatePlannedMeal::class)->handle($planned, $workspace['user'], 2.25, PlannedMealStatus::Skipped, 'Plans changed.');

    expect($slot->refresh()->participants)->toHaveCount(2)
        ->and((float) $slot->participants->firstWhere('id', $secondPerson->id)->pivot->servings)->toBe(0.75)
        ->and($planned->refresh()->type)->toBe(PlannedMealType::Takeaway)
        ->and($planned->status)->toBe(PlannedMealStatus::Skipped)
        ->and($planned->notes)->toBe('Plans changed.');
});

it('supports leftovers linked to their source meal and accessible rescheduling', function () {
    $workspace = m3PlanningWorkspace();
    $first = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $second = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Lunch, $workspace['team']->people);
    $third = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDays(2), MealSlotKind::Lunch, $workspace['team']->people);
    $source = app(SelectPlannedMeal::class)->handle($first, $workspace['user'], PlannedMealType::Custom, title: 'Pulled pork');
    $leftovers = app(SelectPlannedMeal::class)->handle($second, $workspace['user'], PlannedMealType::Leftovers, sourcePlannedMeal: $source);

    app(MovePlannedMeal::class)->handle($leftovers, $third, $workspace['user']);

    expect($leftovers->refresh()->source_planned_meal_id)->toBe($source->id)
        ->and($leftovers->meal_slot_id)->toBe($third->id)
        ->and($leftovers->title)->toBe('Leftovers: Pulled pork');
});

it('tracks milestones revisions stale derived data and concurrent edits', function () {
    $workspace = m3PlanningWorkspace();
    app(RecordMealPlanMilestone::class)->handle($workspace['plan'], $workspace['user'], MealPlanMilestoneKind::PlanningConfirmed);
    $confirmedRevision = $workspace['plan']->refresh()->revision;
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Breakfast, $workspace['team']->people);

    expect($workspace['plan']->refresh()->revision)->toBe($confirmedRevision + 1)
        ->and($workspace['plan']->derived_data_stale_at)->not->toBeNull()
        ->and($workspace['plan']->derived_data_stale_reason)->toContain('breakfast')
        ->and($workspace['plan']->revisions()->latest('revision')->firstOrFail()->changes['meal_slot_id'])->toBe($slot->id);

    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Toast', expectedRevision: $confirmedRevision);
})->throws(ValidationException::class, 'This plan changed elsewhere');

it('explains recommendations using safety preferences recency cost and effort', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $person = $workspace['team']->people()->sole();
    app(RecordConstraint::class)->handle(
        $workspace['team'],
        $workspace['user'],
        ConstraintKind::Allergy,
        'peanut',
        directlyConfirmed: true,
        person: $person,
    );
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, [$person]);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Recipe, $recipe->latestVersion, estimatedCost: 14.5);

    expect($planned->recommendation_explanation['safety'])->toContain('peanut')
        ->and($planned->recommendation_explanation['recency'])->toBe('Not found in earlier plans.')
        ->and($planned->recommendation_explanation['effort'])->toBe('35 minutes total.')
        ->and($planned->recommendation_explanation['cost'])->toBe('Estimated at $14.50.');
});

it('exposes authorised recipe creation import versioning and reading over HTTP', function () {
    $workspace = m3PlanningWorkspace();
    $payload = [
        'title' => 'Air fryer schnitzel',
        'summary' => 'Crisp and quick.',
        'servings' => 2,
        'prep_minutes' => 15,
        'cook_minutes' => 18,
        'ingredients' => [['name' => 'Chicken breast', 'quantity' => 2, 'unit' => 'pieces']],
        'steps' => [['instruction' => 'Crumb and air fry.', 'timer_minutes' => 18]],
        'equipment' => ['Air fryer'],
        'notices' => [],
    ];

    $this->withoutVite()->actingAs($workspace['user'])->post(route('recipes.store'), $payload)->assertRedirect();
    $recipe = $workspace['team']->recipes()->sole();
    $this->get(route('recipes.index'))->assertInertia(fn (Assert $page) => $page
        ->component('recipes/index')
        ->where('recipes.0.title', 'Air fryer schnitzel'));
    $this->get(route('recipes.show', $recipe))->assertInertia(fn (Assert $page) => $page
        ->component('recipes/show')
        ->where('recipe.versions.0.version', 1)
        ->where('recipe.versions.0.ingredients.0.name', 'Chicken breast'));
    $this->post(route('recipes.versions.store', $recipe), [...$payload, 'title' => 'Air fryer schnitzel updated'])->assertRedirect();
    $this->post(route('recipes.import'), [
        'source_text' => "Toast\nIngredients\n- Bread\nSteps\n1. Toast the bread.",
    ])->assertRedirect();

    expect($recipe->versions()->count())->toBe(2)
        ->and($workspace['team']->recipes()->count())->toBe(2);
});

it('exposes the complete planning workspace mutations over HTTP', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $person = $workspace['team']->people()->sole();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, [$person]);
    $this->actingAs($workspace['user']);

    $this->post(route('meal-slots.planned-meal.store', $slot), [
        'type' => 'recipe',
        'recipe_version_id' => $recipe->latestVersion->id,
        'estimated_cost' => 12.5,
    ])->assertRedirect();
    $planned = $slot->plannedMeal()->sole();
    $this->put(route('meal-slots.participants.update', $slot), [
        'participants' => [['person_id' => $person->id, 'servings' => 1.5]],
    ])->assertRedirect();
    $this->put(route('planned-meals.update', $planned), [
        'servings' => 1.5,
        'status' => 'skipped',
        'notes' => 'Not tonight.',
    ])->assertRedirect();
    $this->post(route('meal-plans.milestones.store', $workspace['plan']), [
        'kind' => 'planning_confirmed',
    ])->assertRedirect();

    $this->withoutVite()->get(route('meal-plans.show', $workspace['plan']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.plan.slots.0.planned_meal.recipe_version.id', $recipe->latestVersion->id)
            ->where('workspace.plan.slots.0.planned_meal.status', 'skipped')
            ->where('workspace.plan.milestones.0.kind', 'planning_confirmed')
            ->where('workspace.recipes.0.id', $recipe->id));
});

it('returns not found for cross-family M3 route-bound resources', function () {
    $workspace = m3PlanningWorkspace();
    $recipe = m3CreateRecipe($workspace);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Outside family');

    $this->actingAs($outsider)->get(route('recipes.show', $recipe))->assertNotFound();
    $this->post(route('meal-slots.planned-meal.store', $slot), ['type' => 'open', 'title' => 'Open'])->assertNotFound();
});

it('lets Chef inspect create and select recipes through replay-safe SDK tools', function () {
    $workspace = m3PlanningWorkspace();
    $conversation = $workspace['plan']->conversations->firstOrFail();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $recipe = m3CreateRecipe($workspace, 'Known recipe');
    $revisionBeforeSelection = $workspace['plan']->refresh()->revision;
    $clientId = (string) Str::uuid();
    ChefAgent::fake([
        new ToolCall('inspect-recipes', 'InspectRecipes', []),
        new ToolCall('create-recipe-1', 'CreateFamilyRecipe', [
            'title' => 'Butter chicken',
            'summary' => 'A mild curry.',
            'servings' => 2,
            'prep_minutes' => 15,
            'cook_minutes' => 30,
            'ingredients' => ['500 g chicken thigh', 'Butter chicken sauce'],
            'steps' => ['Brown the chicken.', 'Simmer in the sauce.'],
        ]),
        new ToolCall('create-recipe-replay', 'CreateFamilyRecipe', [
            'title' => 'Butter chicken',
            'summary' => 'A mild curry.',
            'servings' => 2,
            'prep_minutes' => 15,
            'cook_minutes' => 30,
            'ingredients' => ['500 g chicken thigh', 'Butter chicken sauce'],
            'steps' => ['Brown the chicken.', 'Simmer in the sauce.'],
        ]),
        new ToolCall('select-meal-1', 'SelectPlanMeal', [
            'meal_slot_id' => $slot->id,
            'type' => 'recipe',
            'recipe_version_id' => $recipe->latestVersion->id,
            'servings' => 2,
        ]),
        new ToolCall('select-meal-replay', 'SelectPlanMeal', [
            'meal_slot_id' => $slot->id,
            'type' => 'recipe',
            'recipe_version_id' => $recipe->latestVersion->id,
            'servings' => 2,
        ]),
        'The recipe is saved and dinner is selected.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Save butter chicken, then use our known recipe for dinner.',
        'client_message_id' => $clientId,
    ]);
    $response->streamedContent();

    expect($workspace['team']->recipes()->where('title', 'Butter chicken')->count())->toBe(1)
        ->and($slot->plannedMeal()->sole()->recipe_version_id)->toBe($recipe->latestVersion->id)
        ->and($workspace['plan']->refresh()->revision)->toBe($revisionBeforeSelection + 1)
        ->and($conversation->messages()->reorder()->latest('id')->firstOrFail()->content)->toBe('The recipe is saved and dinner is selected.');
});
