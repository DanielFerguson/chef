<?php

use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RecordPreference;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Recipes\MaterializePlannedMealRecipe;
use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Actions\Recipes\PreparePlannedMealRecipe;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Agents\RecipeDraftingAgent;
use App\Ai\Contracts\RecipeDrafter;
use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;
use App\Ai\LaravelAiRecipeDrafter;
use App\Enums\ConstraintKind;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealRecipePreparationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Enums\ShoppingListItemCategory;
use App\Jobs\FinishPreparingShoppingListJob;
use App\Jobs\MaterializePlannedMealRecipeJob;
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

it('materialises an accepted cookable proposal into one immutable recipe version', function () {
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
    $preparation = $planned->recipePreparation;
    app(MaterializePlannedMealRecipe::class)->handle($preparation);
    $planned->refresh();
    $preparation->refresh();
    $revision = $workspace['plan']->refresh()->revision;

    expect($planned->type)->toBe(PlannedMealType::Recipe)
        ->and($planned->recipe_version_id)->not->toBeNull()
        ->and($preparation->status)->toBe(PlannedMealRecipePreparationStatus::Completed)
        ->and($preparation->recipe_version_id)->toBe($planned->recipe_version_id)
        ->and($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1);

    app(MaterializePlannedMealRecipe::class)->handle($preparation);

    expect($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision);
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
    $spy = new class implements RecipeDrafter
    {
        public ?RecipeDraftRequest $request = null;

        public function draft(RecipeDraftRequest $request): RecipeDraft
        {
            $this->request = $request;

            return m41ValidDraft();
        }
    };
    app()->instance(RecipeDrafter::class, $spy);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, [$tahlia]);

    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Chicken katsu curry');

    expect($spy->request)->not->toBeNull()
        ->and($spy->request->preferences)->toContain([
            'owner' => 'Tahlia',
            'subject' => 'pesto',
            'sentiment' => 'dislike',
            'provenance' => 'stated',
        ])
        ->and($spy->request->constraints[0]['owner'])->toBe('Tahlia')
        ->and($spy->request->constraints[0]['kind'])->toBe('allergy')
        ->and($spy->request->constraints[0]['subject'])->toBe('peanuts')
        ->and(collect($spy->request->preferences)->pluck('subject'))->not->toContain('garlic');
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

    expect($workspace['plan']->recipePreparations()->count())->toBe(0)
        ->and($workspace['plan']->plannedMeals()->whereNull('recipe_version_id')->count())->toBe(4);
});

it('cancels an obsolete queued recipe when a custom meal becomes non-recipe', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $custom = app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Chicken dinner',
    );
    $preparation = $custom->recipePreparation;
    app()->instance(RecipeDrafter::class, new class($slot, $workspace['user']) implements RecipeDrafter
    {
        public function __construct(
            private readonly MealSlot $slot,
            private readonly User $user,
        ) {}

        public function draft(RecipeDraftRequest $request): RecipeDraft
        {
            app(SelectPlannedMeal::class)->handle(
                $this->slot,
                $this->user,
                PlannedMealType::Takeaway,
                title: 'Takeaway night',
            );

            return m41ValidDraft('Obsolete chicken dinner');
        }
    });

    app(MaterializePlannedMealRecipe::class)->handle($preparation);
    $takeaway = $slot->plannedMeal()->sole();
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    expect($takeaway->refresh()->type)->toBe(PlannedMealType::Takeaway)
        ->and($takeaway->recipe_version_id)->toBeNull()
        ->and($preparation->refresh()->status)->toBe(PlannedMealRecipePreparationStatus::Cancelled)
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
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    Queue::assertPushed(MaterializePlannedMealRecipeJob::class, 1);
    expect($planned->recipePreparation->status)->toBe(PlannedMealRecipePreparationStatus::Pending)
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
    $preparation = $planned->recipePreparation;
    app()->instance(RecipeDrafter::class, new class implements RecipeDrafter
    {
        public function draft(RecipeDraftRequest $request): RecipeDraft
        {
            throw new RuntimeException('Provider secret and raw failure');
        }
    });

    expect(fn () => app(MaterializePlannedMealRecipe::class)->handle($preparation))
        ->toThrow(RuntimeException::class, 'Provider secret');

    $failed = $preparation->refresh();
    expect($failed->status)->toBe(PlannedMealRecipePreparationStatus::Failed)
        ->and($failed->attempts)->toBe(1)
        ->and($failed->failure_message)->toBe('Chef could not prepare this recipe.')
        ->and($failed->failure_message)->not->toContain('Provider secret');

    app()->instance(RecipeDrafter::class, new class implements RecipeDrafter
    {
        public function draft(RecipeDraftRequest $request): RecipeDraft
        {
            return m41ValidDraft('Butter chicken');
        }
    });
    $retry = app(PreparePlannedMealRecipe::class)->handle($planned->refresh(), $workspace['user']);
    app(MaterializePlannedMealRecipe::class)->handle($retry);
    $revision = $workspace['plan']->refresh()->revision;
    app(MaterializePlannedMealRecipe::class)->handle($retry->refresh());

    expect($retry->refresh()->status)->toBe(PlannedMealRecipePreparationStatus::Completed)
        ->and($retry->attempts)->toBe(2)
        ->and($workspace['team']->recipes()->count())->toBe(1)
        ->and($workspace['team']->recipes()->sole()->versions()->count())->toBe(1)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision);
});

it('rejects malformed structured drafts before creating a recipe', function () {
    Queue::fake();
    $workspace = m41Workspace();
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $planned = app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Custom, title: 'Mystery dinner');
    app()->instance(RecipeDrafter::class, new class implements RecipeDrafter
    {
        public function draft(RecipeDraftRequest $request): RecipeDraft
        {
            return new RecipeDraft('', null, 0, null, null, [], [], [], []);
        }
    });

    expect(fn () => app(MaterializePlannedMealRecipe::class)->handle($planned->recipePreparation))
        ->toThrow(ValidationException::class);

    expect($planned->recipePreparation->refresh()->status)->toBe(PlannedMealRecipePreparationStatus::Failed)
        ->and($workspace['team']->recipes()->count())->toBe(0)
        ->and($planned->refresh()->recipe_version_id)->toBeNull();
});

it('uses the Laravel AI SDK structured fake behind the Chef-owned drafting boundary', function () {
    $workspace = m41Workspace();
    $request = new RecipeDraftRequest(
        teamId: $workspace['team']->id,
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
    );
    $draft = m41ValidDraft('Pork katsu');
    RecipeDraftingAgent::fake([$draft->toArray()])->preventStrayPrompts();

    $result = app(LaravelAiRecipeDrafter::class)->draft($request);

    expect($result->title)->toBe('Pork katsu')
        ->and($result->ingredients)->toHaveCount(2);
    RecipeDraftingAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $instructions = (string) $prompt->agent->instructions();

        return str_contains($instructions, 'Tahlia')
            && str_contains($instructions, 'pesto')
            && str_contains($instructions, 'peanuts');
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

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.shopping-list.generate', $workspace['plan']))
        ->assertRedirect(route('meal-plans.shopping.show', $workspace['plan']));

    expect($legacyMeal->recipePreparation()->count())->toBe(1)
        ->and($legacyMeal->recipePreparation->status)->toBe(PlannedMealRecipePreparationStatus::Pending)
        ->and($workspace['plan']->shoppingList()->sole()->revision)->toBe(0);
    Queue::assertPushed(MaterializePlannedMealRecipeJob::class, 1);
    Queue::assertPushed(FinishPreparingShoppingListJob::class, 1);

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other household');
    expect($workspace['user']->can('view', $legacyMeal->recipePreparation))->toBeTrue()
        ->and($outsider->can('view', $legacyMeal->recipePreparation))->toBeFalse()
        ->and(fn () => app(PrepareMealPlanRecipes::class)->handle($workspace['plan'], $outsider))
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
    $planned = app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Retry dinner',
    );
    $planned->recipePreparation->update([
        'status' => PlannedMealRecipePreparationStatus::Failed,
        'failure_code' => 'provider_error',
        'failure_message' => 'Chef could not prepare this recipe.',
    ]);

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.recipes.prepare', $workspace['plan']))
        ->assertRedirect();

    expect($planned->recipePreparation->refresh()->status)->toBe(PlannedMealRecipePreparationStatus::Pending)
        ->and($planned->recipePreparation->failure_code)->toBeNull()
        ->and($planned->recipePreparation->failure_message)->toBeNull();
    Queue::assertPushed(MaterializePlannedMealRecipeJob::class, 2);
});

it('updates the structured shopping list through the same durable conversation', function () {
    $workspace = m41Workspace();
    $recipe = m41CreateRecipe($workspace);
    $slot = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    app(SelectPlannedMeal::class)->handle($slot, $workspace['user'], PlannedMealType::Recipe, $recipe->latestVersion);
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
