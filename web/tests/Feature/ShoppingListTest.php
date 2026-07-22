<?php

use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Planning\UpdatePlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\AddShoppingListItems;
use App\Actions\Shopping\CompleteShoppingList;
use App\Actions\Shopping\DeleteShoppingListItem;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Shopping\MatchRetailProduct;
use App\Actions\Shopping\RecordOrderSnapshot;
use App\Actions\Shopping\ResolvePlannedMealIngredients;
use App\Actions\Shopping\SetShoppingBudget;
use App\Actions\Shopping\SetShoppingFulfilment;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\MessageRole;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListStatus;
use App\Models\PlannedMeal;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function shoppingListWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Shopping family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Satay chicken',
        'A quick dinner.',
        2,
        10,
        20,
        [
            ['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g'],
            ['name' => 'Coconut milk', 'quantity' => 1, 'unit' => 'litre'],
            ['name' => 'Coriander', 'quantity' => null, 'unit' => null, 'optional' => true],
        ],
        [['instruction' => 'Cook everything together.']],
    );
    $firstSlot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    $secondSlot = app(CreateMealSlot::class)->handle($plan, $user, today()->addDay(), MealSlotKind::Dinner, $team->people);
    $firstMeal = app(SelectPlannedMeal::class)->handle($firstSlot, $user, PlannedMealType::Recipe, $recipe->latestVersion, servings: 4);
    $secondMeal = app(SelectPlannedMeal::class)->handle($secondSlot, $user, PlannedMealType::Recipe, $recipe->latestVersion, servings: 2);
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);
    app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);
    $plan = $plan->refresh();

    return compact('user', 'team', 'plan', 'recipe', 'firstMeal', 'secondMeal');
}

it('stores delivery or pickup intent without extending Chef into checkout', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);

    $this->actingAs($workspace['user'])
        ->put(route('shopping-lists.fulfilment.update', $list), [
            'fulfilment_method' => 'delivery',
        ])
        ->assertRedirect();

    expect($list->refresh()->fulfilment_method)->toBe('delivery')
        ->and($list->fulfilment_scheduled_for)->toBeNull()
        ->and($list->fulfilment_confirmed_at)->toBeNull();

    expect(fn () => app(SetShoppingFulfilment::class)->handle($list, $workspace['user'], 'courier'))
        ->toThrow(ValidationException::class, 'Choose delivery or pickup');

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect(fn () => app(SetShoppingFulfilment::class)->handle($list, $outsider, 'pickup'))
        ->toThrow(AuthorizationException::class);
});

it('generates an idempotent traceable list from scaled recipe requirements', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $again = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($again->is($list))->toBeTrue()
        ->and($list->refresh()->revision)->toBe(1)
        ->and($list->items)->toHaveCount(3)
        ->and($list->revisions)->toHaveCount(1)
        ->and($workspace['plan']->milestones()->where('kind', 'shopping_list_generated')->exists())->toBeTrue();

    $chicken = $list->items->firstWhere('normalized_name', 'chicken breast');
    $milk = $list->items->firstWhere('normalized_name', 'coconut milk');
    $coriander = $list->items->firstWhere('normalized_name', 'coriander');

    expect($chicken->quantity)->toBe(1500.0)
        ->and($chicken->category)->toBe(ShoppingListItemCategory::MeatAndSeafood)
        ->and($chicken->unit)->toBe('g')
        ->and($chicken->sources)->toHaveCount(2)
        ->and($chicken->sources->pluck('planned_meal_id')->sort()->values()->all())->toBe([
            $workspace['firstMeal']->id,
            $workspace['secondMeal']->id,
        ])
        ->and($milk->quantity)->toBe(3000.0)
        ->and($milk->category)->toBe(ShoppingListItemCategory::Pantry)
        ->and($milk->unit)->toBe('ml')
        ->and($coriander->quantity)->toBeNull()
        ->and($coriander->category)->toBe(ShoppingListItemCategory::FruitAndVeg)
        ->and($coriander->optional)->toBeTrue();
});

it('persists automatic shopping categories and allows household corrections', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $soap = app(AddShoppingListItem::class)->handle(
        $list,
        $workspace['user'],
        'Hand soap',
        1,
        'refill',
        null,
        false,
        1,
    );

    expect($soap->category)->toBe(ShoppingListItemCategory::Household);

    app(UpdateShoppingListItem::class)->handle($soap, $workspace['user'], [
        'category' => ShoppingListItemCategory::Other->value,
    ], 2);
    expect($soap->refresh()->category)->toBe(ShoppingListItemCategory::Other);

    app(UpdateShoppingListItem::class)->handle($soap, $workspace['user'], [
        'name' => 'SodaStream Pepsi mix',
    ], 3);

    $snapshotItem = collect($list->refresh()->revisions()->latest('revision')->firstOrFail()->snapshot['items'])
        ->firstWhere('id', $soap->id);

    expect($soap->refresh()->category)->toBe(ShoppingListItemCategory::Drinks)
        ->and($snapshotItem['category'])->toBe(ShoppingListItemCategory::Drinks->value);

    expect(fn () => app(UpdateShoppingListItem::class)->handle($soap, $workspace['user'], [
        'category' => 'not_a_category',
    ], 4))->toThrow(ValidationException::class, 'valid shopping category');

    $chicken = $list->items()->where('normalized_name', 'chicken breast')->sole();
    app(UpdateShoppingListItem::class)->handle($chicken, $workspace['user'], [
        'category' => ShoppingListItemCategory::Other->value,
    ], 4);
    app(UpdatePlannedMeal::class)->handle(
        $workspace['firstMeal'],
        $workspace['user'],
        4,
        PlannedMealStatus::Planned,
        'Use the corrected shopping aisle.',
    );
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($list->items()->where('normalized_name', 'chicken breast')->sole()->category)
        ->toBe(ShoppingListItemCategory::Other);
});

it('combines equivalent singular and plural shopping units', function () {
    $workspace = shoppingListWorkspace();
    $secondRecipe = app(CreateRecipe::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'Rice side',
        null,
        2,
        null,
        null,
        [['name' => 'Jasmine rice', 'quantity' => 1, 'unit' => 'cup']],
        [['instruction' => 'Cook the rice.']],
    );
    $thirdSlot = app(CreateMealSlot::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        today()->addDays(2),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $thirdSlot,
        $workspace['user'],
        PlannedMealType::Recipe,
        $secondRecipe->latestVersion,
        servings: 2,
    );
    $secondRecipe->latestVersion->ingredients()->create([
        'name' => 'Jasmine rice',
        'quantity' => 1.5,
        'unit' => 'cups',
        'optional' => false,
        'position' => 2,
    ]);
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $rice = $list->items()->where('normalized_name', 'jasmine rice')->get();

    expect($rice)->toHaveCount(1)
        ->and($rice->first()->unit)->toBe('ml')
        ->and($rice->first()->quantity)->toBe(625.0);
});

it('supports manual editing pantry exclusions completion and revision history', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $bread = app(AddShoppingListItem::class)->handle(
        $list,
        $workspace['user'],
        'Toast white bread',
        2,
        'loaves',
        'Budget option',
        staple: true,
        expectedRevision: 1,
    );
    app(UpdateShoppingListItem::class)->handle($bread, $workspace['user'], [
        'quantity' => 3,
        'note' => 'Two for the freezer',
    ], 2);
    $coriander = $list->items()->where('normalized_name', 'coriander')->sole();
    app(UpdateShoppingListItem::class)->handle($coriander, $workspace['user'], ['included' => false], 3);
    $milk = $list->items()->where('normalized_name', 'coconut milk')->sole();
    app(UpdateShoppingListItem::class)->handle($milk, $workspace['user'], ['in_pantry' => true], 4);
    $chicken = $list->items()->where('normalized_name', 'chicken breast')->sole();
    app(UpdateShoppingListItem::class)->handle($chicken, $workspace['user'], ['checked' => true], 5);
    app(UpdateShoppingListItem::class)->handle($bread, $workspace['user'], ['checked' => true], 6);
    app(CompleteShoppingList::class)->handle($list->refresh(), $workspace['user'], 7);

    expect($list->refresh()->status)->toBe(ShoppingListStatus::Completed)
        ->and($list->completed_at)->not->toBeNull()
        ->and($list->revision)->toBe(8)
        ->and($list->revisions)->toHaveCount(8)
        ->and($bread->refresh()->quantity)->toBe(3.0)
        ->and($bread->note)->toBe('Two for the freezer')
        ->and($workspace['plan']->milestones()->where('kind', 'shopping_completed')->exists())->toBeTrue();

    app(DeleteShoppingListItem::class)->handle($bread, $workspace['user'], 8);
    expect($list->refresh()->status)->toBe(ShoppingListStatus::Draft)
        ->and($list->items()->whereKey($bread->id)->exists())->toBeFalse();
});

it('atomically adds and replays a conversational batch as one shopping revision', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $message = $workspace['plan']->conversations()->sole()->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'Add a Scrub Daddy sponge pack and paper towels.',
    ]);
    $requested = [
        ['name' => 'Scrub Daddy sponge pack', 'quantity' => null, 'unit' => null, 'note' => null, 'staple' => false],
        ['name' => 'Paper towels', 'quantity' => 1, 'unit' => 'pack', 'note' => null, 'staple' => true],
    ];

    $items = app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, $requested);

    expect($items)->toHaveCount(2)
        ->and($items->pluck('normalized_name')->all())->toBe(['scrub daddy sponge pack', 'paper towels'])
        ->and($items->every(fn ($item) => $item->category === ShoppingListItemCategory::Household))->toBeTrue()
        ->and($items->every(fn ($item) => $item->source_message_id === $message->id))->toBeTrue()
        ->and($list->refresh()->revision)->toBe(2)
        ->and($list->revisions()->where('revision', 2)->sole()->summary)->toBe('Added Scrub Daddy sponge pack and Paper towels');

    $replayed = app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, $requested);

    expect($replayed->pluck('id')->all())->toBe($items->pluck('id')->all())
        ->and($list->refresh()->revision)->toBe(2)
        ->and($list->revisions()->count())->toBe(2)
        ->and($list->items()->whereIn('normalized_name', ['scrub daddy sponge pack', 'paper towels'])->count())->toBe(2);
});

it('validates every conversational addition before writing any item', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $message = $workspace['plan']->conversations()->sole()->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'Add milk and an invalid item.',
    ]);
    $initialCount = $list->items()->count();

    expect(fn () => app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, [
        ['name' => 'Milk', 'quantity' => 3, 'unit' => 'litres', 'note' => null, 'staple' => false],
        ['name' => '', 'quantity' => null, 'unit' => null, 'note' => null, 'staple' => false],
    ]))->toThrow(ValidationException::class);

    expect($list->items()->count())->toBe($initialCount)
        ->and($list->refresh()->revision)->toBe(1)
        ->and($list->revisions()->count())->toBe(1);
});

it('merges conversational additions onto the latest list revision', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    app(AddShoppingListItem::class)->handle($list, $workspace['user'], 'Milo', 1, 'tin', null, false, 1);
    $message = $workspace['plan']->conversations()->sole()->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'Add a Scrub Daddy sponge pack and paper towels.',
    ]);

    app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, [
        ['name' => 'Scrub Daddy sponge pack', 'quantity' => null, 'unit' => null, 'note' => null, 'staple' => false],
        ['name' => 'Paper towels', 'quantity' => null, 'unit' => null, 'note' => null, 'staple' => false],
    ]);

    expect($list->refresh()->revision)->toBe(3)
        ->and($list->items()->whereIn('normalized_name', ['milo', 'scrub daddy sponge pack', 'paper towels'])->count())->toBe(3);
});

it('adds only missing items when a previous conversation turn partially succeeded', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    app(AddShoppingListItem::class)->handle($list, $workspace['user'], 'Scrub Daddy sponge pack', 1, 'pack', null, false, 1);
    $message = $workspace['plan']->conversations()->sole()->messages()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'role' => MessageRole::User,
        'content' => 'Add a Scrub Daddy sponge pack and paper towels.',
    ]);

    $items = app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, [
        ['name' => 'Scrub Daddy sponge pack', 'quantity' => 1, 'unit' => 'pack', 'note' => null, 'staple' => false],
        ['name' => 'Paper towels', 'quantity' => 1, 'unit' => 'pack', 'note' => null, 'staple' => false],
    ]);

    expect($items)->toHaveCount(2)
        ->and($list->items()->where('normalized_name', 'scrub daddy sponge pack')->count())->toBe(1)
        ->and($list->items()->where('normalized_name', 'paper towels')->count())->toBe(1)
        ->and($list->refresh()->revision)->toBe(3)
        ->and($list->revisions()->where('revision', 3)->sole()->summary)->toBe('Added Paper towels');
});

it('rejects conversational additions from a different household', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $outsider = User::factory()->create();
    $otherTeam = app(CreateTeamForUser::class)->handle($outsider, 'Another household');
    $otherPlan = app(StartMealPlan::class)->handle($otherTeam, $outsider, today(), today());
    $message = $otherPlan->conversations()->sole()->messages()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $outsider->id,
        'role' => MessageRole::User,
        'content' => 'Add paper towels.',
    ]);
    $initialCount = $list->items()->count();

    expect(fn () => app(AddShoppingListItems::class)->handle($list, $workspace['user'], $message, [
        ['name' => 'Paper towels', 'quantity' => 1, 'unit' => 'pack', 'note' => null, 'staple' => false],
    ]))->toThrow(AuthorizationException::class);

    expect($list->items()->count())->toBe($initialCount)
        ->and($list->refresh()->revision)->toBe(1);
});

it('refuses to complete a stale or unfinished list', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);

    expect(fn () => app(CompleteShoppingList::class)->handle($list, $workspace['user'], 1))
        ->toThrow(ValidationException::class, 'Buy, order, or mark every included item');

    app(UpdatePlannedMeal::class)->handle(
        $workspace['firstMeal'],
        $workspace['user'],
        4,
        PlannedMealStatus::Planned,
        'Use chicken thigh instead.',
    );
    app(UpdatePlannedMeal::class)->handle(
        $workspace['secondMeal'],
        $workspace['user'],
        2,
        PlannedMealStatus::Planned,
        'Add extra vegetables.',
    );

    expect($list->refresh()->stale_at)->not->toBeNull()
        ->and($list->stale_reason)->toContain('Satay chicken')
        ->and($list->stale_diff['from_plan_revision'])->toBeLessThan($list->stale_diff['to_plan_revision'])
        ->and($list->stale_diff['changes'])->toHaveCount(2)
        ->and($list->stale_diff['changes'][0]['summary'])->toContain('Satay chicken');

    expect(fn () => app(CompleteShoppingList::class)->handle($list, $workspace['user'], 1))
        ->toThrow(ValidationException::class, 'Regenerate');

    $item = $list->items()->firstOrFail();
    expect(fn () => app(AddShoppingListItem::class)->handle(
        $list,
        $workspace['user'],
        'Should not be added',
        null,
        null,
        null,
        false,
        $list->revision,
    ))->toThrow(ValidationException::class, 'Regenerate');
    expect(fn () => app(UpdateShoppingListItem::class)->handle(
        $item,
        $workspace['user'],
        ['note' => 'Should not change'],
        $list->revision,
    ))->toThrow(ValidationException::class, 'Regenerate');
    expect(fn () => app(DeleteShoppingListItem::class)->handle(
        $item,
        $workspace['user'],
        $list->revision,
    ))->toThrow(ValidationException::class, 'Regenerate');

    expect($item->refresh()->note)->toBeNull()
        ->and($list->items()->whereKey($item->id)->exists())->toBeTrue();
});

it('requires revisions for conflicting list edits while merging independent check offs', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $first = $list->items()->firstOrFail();
    $second = $list->items()->whereKeyNot($first->id)->firstOrFail();

    expect(fn () => app(UpdateShoppingListItem::class)->handle(
        $first,
        $workspace['user'],
        ['note' => 'Missing revision'],
    ))->toThrow(ValidationException::class, 'current shopping-list revision is required');

    app(UpdateShoppingListItem::class)->handle($first, $workspace['user'], ['checked' => true]);
    app(UpdateShoppingListItem::class)->handle($second, $workspace['user'], ['checked' => true]);

    expect($first->refresh()->checked)->toBeTrue()
        ->and($second->refresh()->checked)->toBeTrue()
        ->and($list->refresh()->revision)->toBe(3);

    $this->actingAs($workspace['user'])
        ->put(route('shopping-list-items.update', $first), ['note' => 'Missing revision'])
        ->assertSessionHasErrors('expected_revision');
});

it('enforces shopping invariants inside reusable domain actions', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $item = $list->items()->firstOrFail();
    $retailer = Retailer::query()->firstOrFail();
    $retailer->update(['active' => false]);

    expect(fn () => app(SetShoppingBudget::class)->handle($workspace['plan'], $workspace['user'], -1))
        ->toThrow(ValidationException::class, 'greater than zero');
    expect(fn () => app(MatchRetailProduct::class)->handle(
        $item,
        $retailer,
        $workspace['user'],
        ['name' => 'Inactive product', 'price' => 1],
        $list->revision,
    ))->toThrow(ValidationException::class, 'active retailer');
    expect(fn () => app(ResolvePlannedMealIngredients::class)->handle(
        $list,
        $workspace['firstMeal'],
        $workspace['user'],
        [['name' => 'Nope']],
        $list->revision,
    ))->toThrow(ValidationException::class, 'Only a planned custom meal');
    foreach ($list->items as $shoppingItem) {
        app(UpdateShoppingListItem::class)->handle($shoppingItem, $workspace['user'], ['checked' => true]);
    }
    app(CompleteShoppingList::class)->handle($list->refresh(), $workspace['user'], $list->refresh()->revision);
    expect(fn () => app(RecordOrderSnapshot::class)->handle($list->refresh(), $workspace['user'], -1))
        ->toThrow(ValidationException::class, 'cannot be negative');
});

it('resolves custom meal ingredients explicitly and keeps their meal traceability', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        today(),
        MealSlotKind::Lunch,
        $workspace['team']->people,
    );
    $customMeal = PlannedMeal::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'meal_slot_id' => $slot->id,
        'selected_by_user_id' => $workspace['user']->id,
        'type' => PlannedMealType::Custom,
        'status' => PlannedMealStatus::Planned,
        'servings' => 2,
        'title' => 'Pulled pork rolls',
    ]);
    $list->refresh()->update([
        'source_plan_revision' => $workspace['plan']->refresh()->revision,
        'stale_at' => null,
        'stale_reason' => null,
        'stale_diff' => null,
    ]);

    app(ResolvePlannedMealIngredients::class)->handle($list, $customMeal, $workspace['user'], [
        ['name' => 'Bread rolls', 'quantity' => 4, 'unit' => 'each'],
        ['name' => 'Coleslaw', 'quantity' => 1, 'unit' => 'bag'],
    ], $list->revision);
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    $rolls = $list->items()->where('normalized_name', 'bread rolls')->sole();
    $resolution = $list->mealResolutions()->where('planned_meal_id', $customMeal->id)->sole();
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Resolution outsider');
    expect($resolution)->not->toBeNull()
        ->and($workspace['user']->can('view', $resolution))->toBeTrue()
        ->and($outsider->can('view', $resolution))->toBeFalse()
        ->and($rolls->getRawOriginal('source_kind'))->toBe('planned_meal')
        ->and($rolls->sources)->toHaveCount(1)
        ->and($rolls->sources->first()->planned_meal_id)->toBe($customMeal->id)
        ->and($list->refresh()->revision)->toBe(2)
        ->and($readiness['recipes_unresolved'])->toBe(0)
        ->and($readiness['recipes_failed'])->toBe(0)
        ->and($readiness['next_action'])->toBe('begin_shopping');

    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    expect($list->items()->where('normalized_name', 'bread rolls')->exists())->toBeTrue();
});

it('stores household and plan budgets retailer preferences matches and immutable order snapshots', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $retailer = Retailer::query()->where('slug', 'coles')->sole();

    $householdBudget = app(SetShoppingBudget::class)->handle($workspace['plan'], $workspace['user'], 150, true);
    $planBudget = app(SetShoppingBudget::class)->handle($workspace['plan'], $workspace['user'], 100);
    $chicken = $list->items()->where('normalized_name', 'chicken breast')->sole();
    app(MatchRetailProduct::class)->handle($chicken, $retailer, $workspace['user'], [
        'name' => 'RSPCA Approved Chicken Breast Fillets',
        'brand' => 'Coles',
        'pack_quantity' => 1,
        'pack_unit' => 'kg',
        'price' => 12.50,
        'pack_count' => 2,
        'preferred' => true,
        'accept_substitutes' => false,
        'maximum_price' => 28,
        'note' => 'Budget-conscious option',
    ], 1);

    expect($householdBudget->meal_plan_id)->toBeNull()
        ->and($planBudget->meal_plan_id)->toBe($workspace['plan']->id)
        ->and($chicken->refresh()->estimated_price)->toBe(25.0)
        ->and($chicken->productMatch->retailProduct->name)->toBe('RSPCA Approved Chicken Breast Fillets')
        ->and($workspace['team']->productPreferences()->sole()->accept_substitutes)->toBeFalse()
        ->and($workspace['team']->productPreferences()->sole()->maximum_price)->toBe(28.0);

    $updatedHouseholdBudget = app(SetShoppingBudget::class)->handle($workspace['plan'], $workspace['user'], 175, true);
    expect($updatedHouseholdBudget->id)->toBe($householdBudget->id)
        ->and($workspace['team']->budgets()->whereNull('meal_plan_id')->count())->toBe(1)
        ->and($updatedHouseholdBudget->amount)->toBe(175.0);

    expect(fn () => $workspace['team']->budgets()->create([
        'meal_plan_id' => null,
        'scope_key' => 'household',
        'amount' => 200,
        'currency' => 'AUD',
    ]))->toThrow(QueryException::class);
    $preference = $workspace['team']->productPreferences()->sole();
    expect(fn () => $workspace['team']->productPreferences()->create([
        'identity_key' => $preference->identity_key,
        'retailer_id' => $retailer->id,
        'normalized_item_name' => 'chicken breast',
    ]))->toThrow(QueryException::class);

    $this->withoutVite();
    $this->actingAs($workspace['user'])
        ->get(route('meal-plans.shopping.show', $workspace['plan']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.product_preferences.0.normalized_item_name', 'chicken breast')
            ->where('workspace.product_preferences.0.preferred_brand', 'Coles')
            ->where('workspace.product_preferences.0.accept_substitutes', false));

    $revision = $list->refresh()->revision;
    foreach ($list->items as $item) {
        app(UpdateShoppingListItem::class)->handle($item, $workspace['user'], ['checked' => true], $revision++);
    }
    app(CompleteShoppingList::class)->handle($list->refresh(), $workspace['user'], $revision);
    $order = app(RecordOrderSnapshot::class)->handle($list->refresh(), $workspace['user'], 31.40, $retailer);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Order outsider');
    $preference = $workspace['team']->productPreferences()->sole();
    $match = $chicken->productMatch;
    $line = $order->lines->firstOrFail();

    expect($order->actual_total)->toBe(31.4)
        ->and($order->estimated_total)->toBe(25.0)
        ->and($order->shopping_list_revision)->toBe($list->refresh()->revision)
        ->and($order->lines)->toHaveCount(3)
        ->and($order->lines->firstWhere('shopping_list_item_id', $chicken->id)->product_name)->toBe('RSPCA Approved Chicken Breast Fillets')
        ->and($workspace['user']->can('view', $householdBudget))->toBeTrue()
        ->and($workspace['user']->can('view', $preference))->toBeTrue()
        ->and($workspace['user']->can('view', $match))->toBeTrue()
        ->and($workspace['user']->can('view', $order))->toBeTrue()
        ->and($workspace['user']->can('view', $line))->toBeTrue()
        ->and($outsider->can('view', $householdBudget))->toBeFalse()
        ->and($outsider->can('view', $preference))->toBeFalse()
        ->and($outsider->can('view', $match))->toBeFalse()
        ->and($outsider->can('view', $order))->toBeFalse()
        ->and($outsider->can('view', $line))->toBeFalse();

    $chicken->productMatch->retailProduct->update(['name' => 'Renamed catalogue product', 'current_price' => 99]);
    expect($order->lines()->where('shopping_list_item_id', $chicken->id)->sole()->product_name)->toBe('RSPCA Approved Chicken Breast Fillets')
        ->and($order->lines()->where('shopping_list_item_id', $chicken->id)->sole()->total_price)->toBe(25.0);
});

it('records exact retailer identity from a validated product URL', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $woolworths = Retailer::query()->where('slug', 'woolworths')->sole();
    $chicken = $list->items()->where('normalized_name', 'chicken breast')->sole();
    $milk = $list->items()->where('normalized_name', 'coconut milk')->sole();
    $this->actingAs($workspace['user']);

    $this->put(route('shopping-list-items.product-match.update', $chicken), [
        'retailer_id' => $woolworths->id,
        'name' => 'Woolworths Chicken Breast Fillets',
        'product_url' => 'https://www.woolworths.com.au/shop/productdetails/123456/chicken-breast-fillets',
        'price' => 13.50,
        'pack_count' => 2,
        'preferred' => true,
        'accept_substitutes' => false,
        'expected_revision' => 1,
    ])->assertSessionHasNoErrors();

    $product = $chicken->refresh()->productMatch->retailProduct;
    $snapshotMatch = collect($list->refresh()->revisions()->latest('revision')->firstOrFail()->snapshot['items'])
        ->firstWhere('id', $chicken->id)['product_match'];

    expect($product->external_id)->toBe('123456')
        ->and($product->product_url)->toBe('https://www.woolworths.com.au/shop/productdetails/123456/chicken-breast-fillets')
        ->and($snapshotMatch['external_id'])->toBe('123456')
        ->and($snapshotMatch['product_url'])->toBe($product->product_url);

    $this->put(route('shopping-list-items.product-match.update', $milk), [
        'retailer_id' => $woolworths->id,
        'name' => 'Wrong retailer product',
        'product_url' => 'https://www.coles.com.au/productdetails/987654',
        'price' => 3,
        'pack_count' => 1,
        'preferred' => true,
        'accept_substitutes' => false,
        'expected_revision' => 2,
    ])->assertSessionHasErrors('product_url');

    expect($milk->refresh()->productMatch)->toBeNull();
});

it('keeps shopping lists actions and route bindings inside the family boundary', function () {
    $workspace = shoppingListWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan'], $workspace['user']);
    $item = $list->items()->firstOrFail();
    $collaborator = User::factory()->create(['current_team_id' => $workspace['team']->id]);
    $workspace['team']->memberships()->create(['user_id' => $collaborator->id, 'role' => 'member']);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $revision = $list->revisions()->firstOrFail();
    $source = $item->sources()->firstOrFail();

    expect($workspace['user']->can('view', $list))->toBeTrue()
        ->and($workspace['user']->can('view', $revision))->toBeTrue()
        ->and($workspace['user']->can('view', $source))->toBeTrue()
        ->and($collaborator->can('view', $list))->toBeTrue()
        ->and($outsider->can('view', $list))->toBeFalse()
        ->and($outsider->can('view', $revision))->toBeFalse()
        ->and($outsider->can('view', $source))->toBeFalse();
    app(UpdateShoppingListItem::class)->handle($item, $collaborator, ['note' => 'Added by collaborator'], $list->revision);
    expect($item->refresh()->note)->toBe('Added by collaborator');
    expect(fn () => app(AddShoppingListItem::class)->handle(
        $list,
        $outsider,
        'Nope',
        null,
        null,
        null,
        false,
        $list->revision,
    ))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateShoppingListItem::class)->handle($item, $outsider, ['checked' => true]))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SetShoppingBudget::class)->handle($workspace['plan'], $outsider, 50))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(MatchRetailProduct::class)->handle(
        $item,
        Retailer::query()->firstOrFail(),
        $outsider,
        ['name' => 'Nope', 'price' => 1],
        $list->revision,
    ))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordOrderSnapshot::class)->handle($list, $outsider, 1))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($outsider)
        ->get(route('meal-plans.shopping.show', $workspace['plan']->id))
        ->assertNotFound();
    $this->put(route('shopping-list-items.update', $item->id), [
        'checked' => true,
        'expected_revision' => $list->revision,
    ])->assertNotFound();
    $this->put(route('meal-plans.shopping-budget.update', $workspace['plan']->id), [
        'amount' => 50,
        'household_default' => false,
    ])->assertNotFound();
    $this->put(route('shopping-list-items.product-match.update', $item->id), [
        'retailer_id' => Retailer::query()->firstOrFail()->id,
        'name' => 'Nope',
        'price' => 1,
        'pack_count' => 1,
        'preferred' => false,
        'accept_substitutes' => true,
        'expected_revision' => $list->revision,
    ])->assertNotFound();
    $this->post(route('shopping-lists.orders.store', $list->id), [
        'actual_total' => 1,
    ])->assertNotFound();
});

it('serves a structured shopping workspace and generates through the http boundary', function () {
    $workspace = shoppingListWorkspace();
    $this->withoutVite();

    $this->actingAs($workspace['user'])
        ->post(route('meal-plans.shopping-list.generate', $workspace['plan']))
        ->assertRedirect(route('meal-plans.shopping.show', $workspace['plan']));

    $this->get(route('meal-plans.shopping.show', $workspace['plan']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('shopping/show')
            ->where('workspace.plan.id', $workspace['plan']->id)
            ->where('workspace.shopping_list.revision', 1)
            ->has('workspace.shopping_list.items', 3)
            ->where('workspace.shopping_list.items.0.category', 'meat_seafood')
            ->has('workspace.shopping_categories', 9)
            ->where('workspace.shopping_categories.0.value', 'fruit_veg')
            ->where('workspace.shopping_categories.0.label', 'Fruit & Veg')
            ->where('workspace.shopping_list.items.0.sources.0.planned_meal.title', 'Satay chicken')
            ->has('workspace.missing_meals', 0)
            ->has('workspace.retailers', 2)
            ->where('workspace.budget.projected_total', 0)
            ->where('workspace.budget.unmatched_items', 3));
});
