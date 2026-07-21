<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\BuildDeterministicShoppingListDraft;
use App\Actions\Shopping\BuildShoppingListDraftRequest;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Shopping\ShoppingItemIdentity;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ShoppingListDraftingAgent;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Ai\Exceptions\ShoppingListDraftUnavailable;
use App\Ai\LaravelAiShoppingListDrafter;
use App\Enums\ConstraintKind;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListGenerationMethod;
use App\Enums\ShoppingListGenerationStatus;
use App\Enums\ShoppingListItemSourceKind;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;

uses(RefreshDatabase::class);

/** @return array{user: User, team: Team, plan: MealPlan, meals: Collection<int, PlannedMeal>} */
function ingredientAwareShoppingWorkspace(?array $recipes = null): array
{
    $recipes ??= [
        'Butter chicken' => [
            ['name' => 'basmati rice', 'quantity' => 150, 'unit' => 'g'],
            ['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'brown sugar', 'quantity' => 1, 'unit' => 'tsp'],
            ['name' => 'fresh ginger', 'quantity' => 10, 'unit' => 'g'],
        ],
        'Beef tacos' => [
            ['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'tomatoes', 'quantity' => 2, 'unit' => 'each'],
            ['name' => 'tomato paste', 'quantity' => 1, 'unit' => 'tbsp'],
        ],
        'Satay chicken' => [
            ['name' => 'jasmine rice', 'quantity' => 1, 'unit' => 'cup'],
            ['name' => 'brown sugar', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'ginger', 'quantity' => 10, 'unit' => 'g'],
            ['name' => 'broccoli', 'quantity' => 1, 'unit' => 'each'],
            ['name' => 'water', 'quantity' => .25, 'unit' => 'cup'],
        ],
        'Honey soy chicken stir-fry' => [
            ['name' => 'jasmine rice', 'quantity' => 150, 'unit' => 'g'],
            ['name' => 'fresh ginger', 'quantity' => 10, 'unit' => 'g'],
            ['name' => 'broccoli', 'quantity' => 1, 'unit' => 'each'],
            ['name' => 'vegetable oil', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'ground ginger', 'quantity' => 1, 'unit' => 'tsp'],
            ['name' => 'tap water', 'quantity' => 2, 'unit' => 'tbsp'],
        ],
        'Chicken schnitzels with slaw' => [
            ['name' => 'whole-egg mayonnaise', 'quantity' => 2, 'unit' => 'tbsp'],
            ['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'sesame seeds', 'quantity' => 1, 'unit' => 'tsp', 'optional' => true],
        ],
        'Steak sandwiches' => [
            ['name' => 'mayonnaise', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'tomato', 'quantity' => 1, 'unit' => 'each'],
            ['name' => 'passata', 'quantity' => 100, 'unit' => 'ml'],
        ],
        'Burgers and oven chips' => [
            ['name' => 'mayonnaise', 'quantity' => 1, 'unit' => 'tbsp'],
            ['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tsp'],
            ['name' => 'tomatoes', 'quantity' => 1, 'unit' => 'each'],
        ],
    ];
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Ingredient-aware family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(count($recipes) - 1));
    $meals = collect();

    foreach (array_values(array_keys($recipes)) as $index => $title) {
        $ingredients = $recipes[$title];
        $recipe = app(CreateRecipe::class)->handle(
            $team,
            $user,
            $title,
            null,
            2,
            null,
            null,
            $ingredients,
            [['instruction' => 'Cook the retained recipe.']],
        );
        $slot = app(CreateMealSlot::class)->handle(
            $plan->refresh(),
            $user,
            today()->addDays($index),
            MealSlotKind::Dinner,
            $team->people,
        );
        $meals->push(app(SelectPlannedMeal::class)->handle(
            $slot,
            $user,
            PlannedMealType::Recipe,
            $recipe->latestVersion,
            servings: 2,
        ));
    }

    app(RecordConstraint::class)->handle(
        $team,
        $user,
        ConstraintKind::Allergy,
        'Peanuts',
        directlyConfirmed: true,
        person: $team->people->firstOrFail(),
        severity: 'severe',
    );
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);
    app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);

    return ['user' => $user, 'team' => $team, 'plan' => $plan->refresh(), 'meals' => $meals];
}

function shopperFriendlyDraft(ShoppingListDraftRequest $request): ShoppingListDraft
{
    $draft = app(BuildDeterministicShoppingListDraft::class)->handle($request);
    $names = [
        'ginger' => 'ginger',
        'tomato' => 'fresh tomatoes',
        'mayonnaise' => 'mayonnaise',
    ];

    return new ShoppingListDraft(array_map(function (array $item) use ($names): array {
        $key = app(ShoppingItemIdentity::class)->key($item['name']);
        $item['name'] = $names[$key] ?? $item['name'];

        return $item;
    }, $draft->items));
}

function bindIngredientAwareDrafter(ShoppingListDraft|Throwable|Closure $result): object
{
    $spy = new class($result) implements ShoppingListDrafter
    {
        public int $calls = 0;

        public ?int $transactionLevel = null;

        public ?ShoppingListDraftRequest $request = null;

        public function __construct(private readonly ShoppingListDraft|Throwable|Closure $result) {}

        public function draft(ShoppingListDraftRequest $request): ShoppingListDraft
        {
            $this->calls++;
            $this->transactionLevel = DB::transactionLevel();
            $this->request = $request;

            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result instanceof Closure ? ($this->result)($request) : $this->result;
        }
    };
    app()->instance(ShoppingListDrafter::class, $spy);

    return $spy;
}

it('uses one OpenAI Responses structured call with OpenAI pinned, Sol, high reasoning, and grouping-only output', function () {
    config()->set('ai.default', Lab::Anthropic);
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.workloads.shopping_list.model', 'gpt-5.6-sol');
    config()->set('ai.workloads.shopping_list.reasoning_effort', 'high');
    $request = new ShoppingListDraftRequest(
        meals: [['id' => 10, 'title' => 'Butter chicken', 'date' => '2026-07-27', 'kind' => 'dinner', 'servings' => 2]],
        requirements: [['id' => 1, 'planned_meal_id' => 10, 'name' => 'chicken thigh fillets', 'quantity' => 500, 'unit' => 'g', 'optional' => false]],
        sources: [1 => ['team_id' => 1, 'planned_meal_id' => 10, 'recipe_ingredient_id' => 20, 'ingredient_id' => null, 'name' => 'chicken thigh fillets', 'canonical_key' => 'chicken thigh fillet', 'quantity' => 500, 'unit' => 'g', 'dimension' => 'mass', 'optional' => false]],
        constraints: [['owner' => 'Tahlia', 'kind' => 'allergy', 'subject' => 'Peanuts', 'details' => null, 'severity' => 'severe', 'planned_meal_ids' => [10]]],
    );
    $structured = ['items' => [[
        'name' => 'chicken thigh fillets',
        'category' => 'meat_seafood',
        'source_requirement_ids' => [1],
    ]]];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'id' => 'resp_shopping_test',
        'status' => 'completed',
        'model' => 'gpt-5.6-sol',
        'output' => [['type' => 'message', 'status' => 'completed', 'content' => [[
            'type' => 'output_text',
            'text' => json_encode($structured, JSON_THROW_ON_ERROR),
            'annotations' => [],
        ]]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
    ])]);

    $agent = new ShoppingListDraftingAgent($request);
    $draft = (new LaravelAiShoppingListDrafter)->draft($request);

    expect($agent)->toBeInstanceOf(HasStructuredOutput::class)
        ->and($agent->provider())->toBe(Lab::OpenAI)
        ->and($agent->model())->toBe('gpt-5.6-sol')
        ->and(array_keys($request->jsonSerialize()))->toBe(['meals', 'requirements', 'safety_constraints'])
        ->and($draft->items)->toHaveCount(1);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $sent): bool {
        $data = $sent->data();

        return $sent->url() === 'https://api.openai.com/v1/responses'
            && $data['model'] === 'gpt-5.6-sol'
            && $data['reasoning']['effort'] === 'high'
            && $data['text']['format']['type'] === 'json_schema'
            && $data['text']['format']['strict'] === true;
    });
});

it('consolidates retained requirements once while Chef derives totals optionality and exact provenance', function () {
    $workspace = ingredientAwareShoppingWorkspace();
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));
    $transactionLevel = DB::transactionLevel();

    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $again = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $items = $list->items()->with('sources')->get()->keyBy(fn ($item) => app(ShoppingItemIdentity::class)->key($item->name));
    $instructions = (string) (new ShoppingListDraftingAgent($spy->request))->instructions();

    expect($spy->calls)->toBe(1)
        ->and($spy->transactionLevel)->toBe($transactionLevel)
        ->and($again->id)->toBe($list->id)
        ->and($list->refresh()->generation_status)->toBe(ShoppingListGenerationStatus::Ready)
        ->and($list->last_generation_method)->toBe(ShoppingListGenerationMethod::OneShot)
        ->and($items)->toHaveKeys(['jasmine rice', 'basmati rice', 'olive oil', 'brown sugar', 'mayonnaise', 'ginger', 'broccoli', 'tomato'])
        ->and($items)->toHaveKeys(['vegetable oil', 'tomato paste', 'passata', 'ground ginger'])
        ->and($items->keys()->contains(fn (string $name) => str_contains($name, 'water')))->toBeFalse()
        ->and($items['jasmine rice']->quantity)->toBeNull()
        ->and($items['jasmine rice']->unit)->toBeNull()
        ->and($items['olive oil']->quantity)->toBe(65.0)
        ->and($items['mayonnaise']->quantity)->toBe(80.0)
        ->and($items['sesame seed']->optional)->toBeTrue()
        ->and($list->items->every(fn ($item) => $item->source_kind === ShoppingListItemSourceKind::PlanGenerated))->toBeTrue()
        ->and($list->items->flatMap->sources->every(fn ($source) => $source->recipe_ingredient_id !== null))->toBeTrue()
        ->and($list->items->flatMap->sources->pluck('planned_meal_id')->unique()->sort()->values()->all())
        ->toBe($workspace['meals']->pluck('id')->sort()->values()->all());

    foreach ($workspace['meals'] as $meal) {
        expect($instructions)->toContain($meal->title)->toContain('"servings": 2');
    }
    foreach (['basmati rice', 'jasmine rice', 'olive oil', 'whole-egg mayonnaise'] as $ingredient) {
        expect($instructions)->toContain($ingredient);
    }
    expect($instructions)->toContain('Peanuts')->toContain('severe')
        ->and($instructions)->not->toContain('recipe_ingredient_id')
        ->and($instructions)->not->toContain('ingredient_id');
});

it('force regenerates while preserving manual and staple rows, category corrections, and revision history', function () {
    $workspace = ingredientAwareShoppingWorkspace();
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $oliveOil = $list->items()->where('normalized_name', 'olive oil')->firstOrFail();
    $oliveOil->update(['category' => 'other']);
    $manual = app(AddShoppingListItem::class)->handle($list, $workspace['user'], 'Birthday candles', 1, 'pack', null, false, 1);
    $staple = app(AddShoppingListItem::class)->handle($list, $workspace['user'], 'Dishwashing tablets', 1, 'box', null, true, 2);

    $regenerated = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user'], force: true);

    expect($spy->calls)->toBe(2)
        ->and($regenerated->items()->whereKey($manual->id)->exists())->toBeTrue()
        ->and($regenerated->items()->whereKey($staple->id)->exists())->toBeTrue()
        ->and($regenerated->items()->where('normalized_name', 'olive oil')->firstOrFail()->category->value)->toBe('other')
        ->and($regenerated->revisions()->latest('revision')->firstOrFail()->snapshot['generation_method'])->toBe('one_shot');
});

dataset('invalid ingredient grouping drafts', [
    'duplicate canonical output names' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'pantry', 'source_requirement_ids' => [1]],
        ['name' => $request->sources[1]['name'].'s', 'category' => 'pantry', 'source_requirement_ids' => array_keys($request->sources)],
    ]),
    'invalid category' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'chemist', 'source_requirement_ids' => array_keys($request->sources)],
    ]),
    'unknown source id' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'pantry', 'source_requirement_ids' => [999999]],
    ]),
    'duplicated source id' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'pantry', 'source_requirement_ids' => [1, 1]],
    ]),
    'missing source ids' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'pantry', 'source_requirement_ids' => [1]],
    ]),
    'unsafe cross ingredient grouping' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => $request->sources[1]['name'], 'category' => 'pantry', 'source_requirement_ids' => array_keys($request->sources)],
    ]),
    'invented water' => fn (ShoppingListDraftRequest $request) => new ShoppingListDraft([
        ['name' => 'tap water', 'category' => 'other', 'source_requirement_ids' => array_keys($request->sources)],
    ]),
]);

it('rejects invalid model output and uses deterministic fallback without a second call', function (Closure $draft) {
    $workspace = ingredientAwareShoppingWorkspace([
        'First dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
        'Second dinner' => [['name' => 'carrots', 'quantity' => 2, 'unit' => 'each']],
    ]);
    $spy = bindIngredientAwareDrafter($draft);

    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);

    expect($spy->calls)->toBe(1)
        ->and($list->last_generation_method)->toBe(ShoppingListGenerationMethod::DeterministicFallback)
        ->and($list->items->every(fn ($item) => $item->source_kind === ShoppingListItemSourceKind::Recipe))->toBeTrue();
})->with('invalid ingredient grouping drafts');

it('falls back once for the Chef-owned provider exception', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(new ShoppingListDraftUnavailable('Provider unavailable'));

    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);

    expect($spy->calls)->toBe(1)
        ->and($list->last_generation_method)->toBe(ShoppingListGenerationMethod::DeterministicFallback)
        ->and($list->generation_status)->toBe(ShoppingListGenerationStatus::Ready);
});

it('does not treat unexpected boundary failures as provider fallback', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(new RuntimeException('Programming failure'));

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']))
        ->toThrow(RuntimeException::class, 'Programming failure');
    expect($spy->calls)->toBe(1)
        ->and($workspace['plan']->shoppingList()->sole()->generation_status)->toBe(ShoppingListGenerationStatus::Failed)
        ->and($workspace['plan']->shoppingList()->sole()->generation_failure_message)->not->toContain('Programming failure');
});

it('records request-building failures durably without calling the model', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $builder = Mockery::mock(BuildShoppingListDraftRequest::class);
    $builder->shouldReceive('handle')->once()->andThrow(new RuntimeException('Request construction failed'));
    app()->instance(BuildShoppingListDraftRequest::class, $builder);
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']))
        ->toThrow(RuntimeException::class, 'Request construction failed');
    $list = $workspace['plan']->shoppingList()->sole();
    expect($spy->calls)->toBe(0)
        ->and($list->generation_status)->toBe(ShoppingListGenerationStatus::Failed)
        ->and($list->generation_failure_code)->toBe('request_build_failed')
        ->and($list->generation_failure_message)->not->toContain('Request construction failed');
});

it('rejects an active claim without a model call and recovers an expired claim', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));
    $list = $workspace['plan']->shoppingList()->create([
        'team_id' => $workspace['team']->id,
        'created_by_user_id' => $workspace['user']->id,
        'source_plan_revision' => $workspace['plan']->revision,
        'status' => 'draft',
        'generation_status' => ShoppingListGenerationStatus::Processing,
        'generation_token' => fake()->uuid(),
        'generation_started_at' => now(),
    ]);

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user'], force: true))
        ->toThrow(ValidationException::class);
    expect($spy->calls)->toBe(0);

    $list->update(['generation_started_at' => now()->subMinutes(6)]);
    $generated = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user'], force: true);
    expect($spy->calls)->toBe(1)
        ->and($generated->generation_attempts)->toBe(1)
        ->and($generated->generation_status)->toBe(ShoppingListGenerationStatus::Ready);
});

it('discards a response if retained requirements change during drafting', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    bindIngredientAwareDrafter(function (ShoppingListDraftRequest $request): ShoppingListDraft {
        DB::table('recipe_ingredients')->where('id', $request->sources[1]['recipe_ingredient_id'])->update(['quantity' => 2]);

        return shopperFriendlyDraft($request);
    });

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']))
        ->toThrow(ValidationException::class);
    $list = $workspace['plan']->shoppingList()->sole();
    expect($list->generation_status)->toBe(ShoppingListGenerationStatus::Pending)
        ->and($list->generation_failure_code)->toBe('context_changed')
        ->and($list->revision)->toBe(0);
});

it('discards a response if a safety constraint changes during the model call', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(function (ShoppingListDraftRequest $request) use ($workspace): ShoppingListDraft {
        app(RecordConstraint::class)->handle(
            $workspace['team'],
            $workspace['user'],
            ConstraintKind::Other,
            'No alcohol',
            directlyConfirmed: true,
        );

        return shopperFriendlyDraft($request);
    });

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']))
        ->toThrow(ValidationException::class);
    $list = $workspace['plan']->shoppingList()->sole();
    expect($spy->calls)->toBe(1)
        ->and($list->generation_status)->toBe(ShoppingListGenerationStatus::Pending)
        ->and($list->generation_failure_code)->toBe('context_changed')
        ->and(app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh())['safety_review_required'])->toBeTrue();
});

it('denies cross-team generation before calling the model', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan'], $outsider, force: true))
        ->toThrow(AuthorizationException::class);
    expect($spy->calls)->toBe(0);
});

it('force regenerates through the authorised command and reports the method', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $spy = bindIngredientAwareDrafter(fn (ShoppingListDraftRequest $request) => shopperFriendlyDraft($request));

    $this->artisan('chef:shopping-list:regenerate', [
        'mealPlan' => $workspace['plan']->id,
        '--user' => $workspace['user']->id,
    ])->expectsOutputToContain('Generation path: one_shot')->assertSuccessful();

    expect($spy->calls)->toBe(1);
});

it('fails the regeneration command cleanly for a cross-team user', function () {
    $workspace = ingredientAwareShoppingWorkspace([
        'Dinner' => [['name' => 'olive oil', 'quantity' => 1, 'unit' => 'tbsp']],
    ]);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->artisan('chef:shopping-list:regenerate', [
        'mealPlan' => $workspace['plan']->id,
        '--user' => $outsider->id,
    ])->expectsOutputToContain('not authorised')->assertFailed();
});
