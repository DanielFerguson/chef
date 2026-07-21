<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\BuildMealPlanRecipeDraftRequest;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Recipes\MaterializeMealPlanRecipes;
use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Agents\MealPlanRecipeDraftingAgent;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;
use App\Ai\LaravelAiMealPlanRecipeDrafter;
use App\Enums\ConstraintKind;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\MessageRole;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Enums\ShoppingListItemCategory;
use App\Jobs\FinishPreparingShoppingListJob;
use App\Jobs\MaterializeMealPlanRecipesJob;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

function m41Workspace(int $days = 0): array
{
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Daniel and Tahlia');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays($days));

    return compact('user', 'team', 'plan');
}

function m41CreateRecipe(array $workspace, string $title = 'Satay chicken')
{
    return app(CreateRecipe::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $title,
        'A quick family dinner.',
        2,
        10,
        20,
        [
            ['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g'],
            ['name' => 'Jasmine rice', 'quantity' => 1, 'unit' => 'cup'],
        ],
        [
            ['instruction' => 'Cook the rice.'],
            ['instruction' => 'Cook the chicken.', 'timer_minutes' => 15],
        ],
    );
}

function m41ValidDraft(string $title = 'Chicken katsu curry'): RecipeDraft
{
    return new RecipeDraft(
        title: $title,
        summary: 'A practical family dinner.',
        servings: 2,
        prepMinutes: 10,
        cookMinutes: 25,
        ingredients: [
            ['name' => 'Chicken breast', 'quantity' => 500.0, 'unit' => 'g', 'preparation' => 'sliced', 'optional' => false],
            ['name' => 'Panko crumbs', 'quantity' => 1.0, 'unit' => 'cup', 'preparation' => null, 'optional' => false],
        ],
        steps: [
            ['instruction' => 'Crumb the chicken.', 'timer_minutes' => null],
            ['instruction' => 'Cook until golden.', 'timer_minutes' => 15],
        ],
        equipment: ['Frying pan'],
        notices: [],
    );
}

/** @param array<int, array<string, mixed>> $meals */
function m41ValidBatch(array $meals): MealPlanRecipeDraft
{
    return new MealPlanRecipeDraft(array_map(function (array $meal): array {
        return ['planned_meal_id' => $meal['planned_meal_id'], ...m41ValidDraft($meal['title'])->toArray()];
    }, $meals));
}

it('materialises a completed plan with one batch job and one immutable recipe version per meal', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $proposal = app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Chicken katsu curry',
        $slot,
        'Crispy chicken with rice and curry sauce.',
        35,
    );

    $planned = app(AcceptMealProposal::class)->handle($proposal, $workspace['user']);
    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $planned->refresh();
    $revision = $workspace['plan']->refresh()->revision;

    expect($planned->type)->toBe(PlannedMealType::Recipe)
        ->and($planned->recipe_version_id)->not->toBeNull()
        ->and($workspace['plan']->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Completed)
        ->and($workspace['plan']->recipe_generation_attempts)->toBe(1)
        ->and($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1);

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    expect($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision);
});

it('waits for every slot and then dispatches exactly one recipe batch for the whole plan', function () {
    Queue::fake();
    $workspace = m41Workspace(2);

    $proposals = collect(['Chicken tacos', 'Beef stir-fry', 'Vegetarian pasta'])
        ->map(function (string $title, int $offset) use ($workspace) {
            $slot = app(CreateMealSlot::class)->handle(
                $workspace['plan'],
                $workspace['user'],
                today()->addDays($offset),
                MealSlotKind::Dinner,
                $workspace['team']->people,
            );

            return app(ProposeMeal::class)->handle(
                $workspace['plan'],
                $workspace['user'],
                $title,
                $slot,
                'A quick family dinner.',
                30,
            );
        });

    foreach ($proposals as $offset => $proposal) {
        app(AcceptMealProposal::class)->handle($proposal, $workspace['user']);

        Queue::assertPushed(MaterializeMealPlanRecipesJob::class, $offset === 2 ? 1 : 0);
    }

    $plan = $workspace['plan']->refresh();
    expect($plan->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($plan->recipe_generation_input['meals'])->toHaveCount(3);

    app(MaterializeMealPlanRecipes::class)->handle($plan);

    expect($workspace['plan']->plannedMeals()->whereNotNull('recipe_version_id')->count())->toBe(3)
        ->and($workspace['team']->recipes()->count())->toBe(3)
        ->and($workspace['plan']->refresh()->recipe_generation_attempts)->toBe(1);
});

it('passes named preferences and explicit safety constraints into recipe drafting', function () {
    $workspace = m41Workspace();
    $tahlia = $workspace['team']->people()->create([
        'name' => 'Tahlia',
        'created_by_user_id' => $workspace['user']->id,
    ]);
    app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'pesto',
        PreferenceSentiment::Dislike,
        PreferenceProvenance::Stated,
        $tahlia,
    );
    app(RecordConstraint::class)->handle(
        $workspace['team'],
        $workspace['user'],
        ConstraintKind::Allergy,
        'peanuts',
        directlyConfirmed: true,
        person: $tahlia,
    );
    $guest = $workspace['team']->people()->create([
        'name' => 'Dinner guest',
        'created_by_user_id' => $workspace['user']->id,
    ]);
    app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'garlic',
        PreferenceSentiment::Dislike,
        PreferenceProvenance::Stated,
        $guest,
    );
    $spy = new class implements MealPlanRecipeDrafter
    {
        public ?MealPlanRecipeDraftRequest $request = null;

        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            $this->request = $request;

            return m41ValidBatch($request->meals);
        }
    };
    app()->instance(MealPlanRecipeDrafter::class, $spy);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, [$tahlia]);

    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Chicken katsu curry');
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $meal = $spy->request?->meals[0];

    expect($spy->request)->not->toBeNull()
        ->and($meal['preferences'])->toContain([
            'owner' => 'Tahlia',
            'subject' => 'pesto',
            'sentiment' => 'dislike',
            'provenance' => 'stated',
        ])
        ->and($meal['constraints'][0]['owner'])->toBe('Tahlia')
        ->and($meal['constraints'][0]['kind'])->toBe('allergy')
        ->and($meal['constraints'][0]['subject'])->toBe('peanuts')
        ->and(collect($meal['preferences'])->pluck('subject'))->not->toContain('garlic');
});

it('includes the plan conversation in the batch without letting later chat invalidate an active draft', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $conversation = $workspace['plan']->conversations()->latest('id')->firstOrFail();
    $conversation->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'Keep every dinner high protein and under 35 minutes.',
    ]);
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Lemon chicken and couscous',
    );
    $pending = $workspace['plan']->refresh();
    $originalFingerprint = $pending->recipe_generation_input_fingerprint;

    $conversation->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'What happens after the recipes are ready?',
    ]);
    $request = app(BuildMealPlanRecipeDraftRequest::class)->handle($pending);

    expect($pending->recipe_generation_input['conversation_context'])->toContain([
        'role' => 'user',
        'content' => 'Keep every dinner high protein and under 35 minutes.',
    ])->and(app(BuildMealPlanRecipeDraftRequest::class)->fingerprint($request))
        ->toBe($originalFingerprint);
});

it('keeps explicit non-recipe states out of recipe preparation', function () {
    $workspace = m41Workspace(3);
    $recipe = m41CreateRecipe($workspace);
    $sourceSlot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $source = app(SelectPlannedMeal::class)->handle($sourceSlot, $workspace['user'], PlannedMealType::Recipe, $recipe->latestVersion);

    foreach ([PlannedMealType::Takeaway, PlannedMealType::EatingOut, PlannedMealType::Open] as $offset => $type) {
        $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDays($offset + 1), MealSlotKind::Dinner, $workspace['team']->people);
        app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], $type, title: Str::headline($type->value));
    }

    $leftoverSlot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDays(3), MealSlotKind::Lunch, $workspace['team']->people);
    app(SelectPlannedMeal::class)->handle($leftoverSlot, $workspace['user'], PlannedMealType::Leftovers, sourcePlannedMeal: $source);

    expect($workspace['plan']->recipe_generation_status)->toBeNull()
        ->and($workspace['plan']->plannedMeals()->whereNull('recipe_version_id')->count())->toBe(4);
});

it('discards a stale batch when a selected custom meal becomes non-recipe', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Chicken dinner',
    );
    app()->instance(MealPlanRecipeDrafter::class, new class($slot, $workspace['user']) implements MealPlanRecipeDrafter
    {
        public function __construct(
            private readonly MealSlot $slot,
            private readonly User $user,
        ) {}

        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            app(SelectPlannedMeal::class)->handle(
                $this->slot,
                $this->user,
                PlannedMealType::Takeaway,
                title: 'Takeaway night',
            );

            return m41ValidBatch($request->meals);
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $takeaway = $slot->plannedMeal()->sole();
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    expect($takeaway->refresh()->type)->toBe(PlannedMealType::Takeaway)
        ->and($takeaway->recipe_version_id)->toBeNull()
        ->and($workspace['team']->recipes()->count())->toBe(0)
        ->and($readiness['recipes_required'])->toBe(0)
        ->and($readiness['recipes_preparing'])->toBe(0)
        ->and($readiness['ready_for_confirmation'])->toBeTrue();
});

it('blocks confirmation and list generation while a cookable recipe is unresolved', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Pulled pork rolls');
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);
    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($readiness['recipes_required'])->toBe(1)
        ->and($readiness['recipes_ready'])->toBe(0)
        ->and($readiness['recipes_preparing'])->toBe(1)
        ->and($readiness['ready_for_confirmation'])->toBeFalse()
        ->and($readiness['next_action'])->toBe('wait_for_recipes');

    expect(fn () => app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']))
        ->toThrow(ValidationException::class, 'finish preparing each cookable recipe');

    $workspace['plan']->forceFill(['planning_confirmed_at' => now()])->save();
    expect(fn () => app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']))
        ->toThrow(ValidationException::class, 'still needs a prepared recipe');
});

it('records safe failures and retries without duplicate recipes or plan revisions', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Butter chicken');
    app()->instance(MealPlanRecipeDrafter::class, new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            throw new RuntimeException('Provider secret and raw failure');
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    $failed = $workspace['plan']->refresh();
    expect($failed->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Failed)
        ->and($failed->recipe_generation_attempts)->toBe(1)
        ->and($failed->recipe_generation_failure_message)->toBe('Chef could not prepare the completed plan’s recipes.')
        ->and($failed->recipe_generation_failure_message)->not->toContain('Provider secret');

    app()->instance(MealPlanRecipeDrafter::class, new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return m41ValidBatch($request->meals);
        }
    });
    app(PrepareMealPlanRecipes::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $revision = $workspace['plan']->refresh()->revision;
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Completed)
        ->and($workspace['plan']->recipe_generation_attempts)->toBe(2)
        ->and($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision);
});

it('rejects malformed structured drafts before creating a recipe', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Mystery dinner');
    app()->instance(MealPlanRecipeDrafter::class, new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft([[
                'planned_meal_id' => $request->meals[0]['planned_meal_id'],
                ...m41ValidDraft('')->toArray(),
                'ingredients' => [],
            ]]);
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Failed)
        ->and($workspace['team']->recipes()->count())->toBe(0)
        ->and($planned->refresh()->recipe_version_id)->toBeNull();
});

it('uses one Sol high Laravel AI SDK structured call behind the Chef-owned batch boundary', function () {
    config()->set('ai.workloads.recipe_batch.model', 'gpt-5.6-sol');
    config()->set('ai.workloads.recipe_batch.reasoning_effort', 'high');
    $meal = (new RecipeDraftRequest(
        teamId: 10,
        mealPlanId: 15,
        plannedMealId: 20,
        mealDate: '2026-07-18',
        mealKind: 'dinner',
        proposalId: 30,
        sourceMessageId: 40,
        householdName: 'Daniel and Tahlia',
        title: 'Pork katsu',
        summary: 'Crispy pork and rice.',
        servings: 2,
        estimatedMinutes: 35,
        preferences: [['owner' => 'Tahlia', 'subject' => 'pesto', 'sentiment' => 'dislike', 'provenance' => 'stated']],
        constraints: [['owner' => 'Tahlia', 'kind' => 'allergy', 'subject' => 'peanuts', 'details' => null, 'severity' => null]],
        otherMeals: [['title' => 'Chicken fried rice', 'date' => '2026-07-19', 'kind' => 'dinner']],
    ))->jsonSerialize();
    $request = new MealPlanRecipeDraftRequest(10, 15, 'Daniel and Tahlia', [$meal], [[
        'role' => 'user',
        'content' => 'Keep every dinner high protein and under 35 minutes.',
    ]]);
    $draft = m41ValidDraft('Pork katsu');
    MealPlanRecipeDraftingAgent::fake([['recipes' => [[
        'planned_meal_id' => 20,
        ...$draft->toArray(),
    ]]]])->preventStrayPrompts();

    $result = (new LaravelAiMealPlanRecipeDrafter)->draft($request);

    expect($result->recipes[0]['title'])->toBe('Pork katsu')
        ->and($result->recipes[0]['ingredients'])->toHaveCount(2);
    MealPlanRecipeDraftingAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $instructions = (string) $prompt->agent->instructions();

        return str_contains($instructions, 'Tahlia')
            && str_contains($instructions, 'pesto')
            && str_contains($instructions, 'peanuts')
            && str_contains($instructions, 'under 35 minutes')
            && $prompt->agent->model() === 'gpt-5.6-sol'
            && $prompt->agent->providerOptions('openai')['reasoning']['effort'] === 'high';
    });
});

it('recovers a confirmed legacy plan through the authorised HTTP workflow', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $legacyMeal = PlannedMeal::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'meal_slot_id' => $slot->id,
        'selected_by_user_id' => $workspace['user']->id,
        'type' => PlannedMealType::Custom,
        'status' => PlannedMealStatus::Planned,
        'servings' => 2,
        'title' => 'Legacy chicken dinner',
    ]);
    $workspace['plan']->forceFill(['planning_confirmed_at' => now()])->save();
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.shopping-list.generate', $workspace['plan']))
        ->assertRedirect(route('meal-plans.shopping.show', $workspace['plan']));

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($workspace['plan']->shoppingList()->sole()->revision)->toBe(0);
    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);
    Queue::assertPushed(FinishPreparingShoppingListJob::class, 1);

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other household');
    expect(fn () => app(PrepareMealPlanRecipes::class)->handle($workspace['plan'], $outsider))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($outsider)
        ->post(route('meal-plans.recipes.prepare', $workspace['plan']->id))
        ->assertNotFound();
});

it('retries failed recipe preparation through the authorised HTTP workflow', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Retry dinner',
    );
    $workspace['plan']->refresh()->update([
        'recipe_generation_status' => MealPlanRecipeGenerationStatus::Failed,
        'recipe_generation_failure_code' => 'provider_error',
        'recipe_generation_failure_message' => 'Chef could not prepare the completed plan’s recipes.',
    ]);

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.recipes.prepare', $workspace['plan']))
        ->assertRedirect();

    expect($workspace['plan']->refresh()->recipe_generation_status)->toBe(MealPlanRecipeGenerationStatus::Pending)
        ->and($workspace['plan']->recipe_generation_failure_code)->toBeNull()
        ->and($workspace['plan']->recipe_generation_failure_message)->toBeNull();
    Queue::assertPushed(MaterializeMealPlanRecipesJob::class, 1);
});

it('updates the structured shopping list through the same durable conversation', function () {
    $workspace = m41Workspace();
    $recipe = m41CreateRecipe($workspace);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Recipe, $recipe->latestVersion);
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $chicken = $list->items()->where('normalized_name', 'chicken breast')->sole();
    $conversation = $workspace['plan']->conversations()->sole();
    ChefAgent::fake([
        new ToolCall('inspect-shopping', 'InspectPlanShoppingList', []),
        new ToolCall('add-milk', 'AddPlanShoppingItems', [
            'items' => [[
                'name' => 'Milk',
                'quantity' => 3,
                'unit' => 'litres',
                'note' => 'Household extra',
                'staple' => true,
            ]],
        ]),
        new ToolCall('have-chicken', 'UpdatePlanShoppingItem', [
            'item_id' => $chicken->id,
            'in_pantry' => true,
            'expected_revision' => 2,
        ]),
        new ToolCall('set-budget', 'SetPlanShoppingBudget', [
            'amount' => 180,
            'household_default' => false,
        ]),
        'Done — I added three litres of milk, marked chicken as already at home, and set the plan budget to $180.',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $conversation), [
        'content' => 'We have chicken. Add three litres of milk and keep the shop below $180.',
        'client_message_id' => (string) Str::uuid(),
    ]);
    $response->streamedContent();

    expect($list->items()->where('normalized_name', 'milk')->sole()->quantity)->toBe(3.0)
        ->and($list->items()->where('normalized_name', 'milk')->sole()->source_kind->value)->toBe('staple')
        ->and($list->items()->where('normalized_name', 'milk')->sole()->category)->toBe(ShoppingListItemCategory::DairyAndEggs)
        ->and($chicken->refresh()->in_pantry)->toBeTrue()
        ->and($workspace['plan']->budget->amount)->toBe(180.0)
        ->and($conversation->messages()->reorder()->where('role', 'user')->latest('id')->firstOrFail()->content)->toContain('three litres of milk')
        ->and($conversation->messages()->reorder()->where('role', 'assistant')->latest('id')->firstOrFail()->content)->toContain('set the plan budget');
});

it('recovers an atomic shopping batch when the provider fails after the tools finish', function () {
    $workspace = m41Workspace();
    $recipe = m41CreateRecipe($workspace);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Recipe, $recipe->latestVersion);
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $conversation = $workspace['plan']->conversations()->sole();
    ChefAgent::fake([
        new ToolCall('add-extras', 'AddPlanShoppingItems', [
            'items' => [
                [
                    'name' => 'Scrub Daddy sponge pack',
                    'quantity' => 1,
                    'unit' => 'pack',
                    'note' => null,
                    'staple' => false,
                ],
                [
                    'name' => 'Paper towels',
                    'quantity' => 1,
                    'unit' => 'pack',
                    'note' => null,
                    'staple' => false,
                ],
            ],
        ]),
        fn () => throw new RuntimeException('Provider failed after the tool result.'),
    ])->preventStrayPrompts();
    $clientId = (string) Str::uuid();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $conversation), [
        'content' => 'Can you please add a Scrub Daddy sponge pack and paper towels?',
        'client_message_id' => $clientId,
    ]);
    $stream = $response->streamedContent();
    $userMessage = $conversation->messages()->where('client_message_id', $clientId)->sole();
    $assistantMessage = $userMessage->response()->sole();

    expect($stream)->toContain('Added Scrub Daddy sponge pack and Paper towels')
        ->not->toContain('"type":"error"')
        ->and($list->refresh()->revision)->toBe(2)
        ->and($list->revisions()->count())->toBe(2)
        ->and($list->items()->whereIn('normalized_name', ['scrub daddy sponge pack', 'paper towels'])->count())->toBe(2)
        ->and($userMessage->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($assistantMessage->metadata['recovered_from_failure'])->toBeTrue();
});
