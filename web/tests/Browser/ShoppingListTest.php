<?php

use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Conversations\CreateUserMessage;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerProductDiscovery;
use App\Automation\Testing\FakeComputerExecutor;
use App\Automation\Testing\FakeRetailerProductDiscovery;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealType;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Enums\ShoppingListGenerationStatus;
use App\Enums\ShoppingListItemSourceKind;
use App\Models\MealPlan;
use App\Models\Retailer;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function browserShoppingWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Shopping family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
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
            ['name' => 'Coconut milk', 'quantity' => 400, 'unit' => 'ml'],
        ],
        [['instruction' => 'Cook everything together.']],
    );
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    app(SelectPlannedMeal::class)->handle($slot, $user, PlannedMealType::Recipe, $recipe->latestVersion, servings: 2);
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);
    app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);

    return compact('user', 'plan');
}

it('takes a confirmed plan through an editable traceable shopping list', function () {
    $workspace = browserShoppingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->pressAndWaitFor('Start shopping list')
        ->assertSee('Shopping list')
        ->assertScript(
            '() => Array.from(document.querySelector(\'[data-sidebar="trigger"]\')?.closest(\'header\')?.querySelectorAll(\'a\') ?? []).some((link) => link.textContent?.trim() === \'Back to plan\')',
        )
        ->assertSee('Full shopping list')
        ->assertSee('2 items · Review pantry, quantities or add an item')
        ->assertScript('() => document.querySelector(\'summary[aria-label="Shopping list items"]\')?.parentElement?.open === false')
        ->assertDontSee('Complete shop')
        ->click('summary[aria-label="Shopping list items"]')
        ->assertSee('Meat & Seafood')
        ->assertSee('Pantry')
        ->assertSee('Satay chicken')
        ->assertDontSee('Match retailer product')
        ->assertNotPresent('[data-slot="table"]')
        ->assertPresent('button[aria-label="Edit Chicken breast"]')
        ->assertScript(
            '() => Array.from(document.querySelectorAll(\'[aria-label="Shopping items view"] button\')).map((button) => button.getAttribute(\'aria-label\')).join(\',\')',
            'Table view,List view',
        )
        ->click('button[aria-label="Table view"]')
        ->assertPresent('[data-slot="table"]')
        ->assertSee('Used for')
        ->assertScript('() => Array.from(document.querySelectorAll("[data-testid=shopping-item-checkbox]")).every((item) => item.getBoundingClientRect().width >= 20 && item.getBoundingClientRect().height >= 20)')
        ->click('button[aria-label="List view"]')
        ->assertPresent('button[aria-label="Edit Chicken breast"]')
        ->assertPresent('button[aria-label="Edit Coconut milk"]')
        ->pressAndWaitFor('Back to plan')
        ->assertSee('Review shopping list')
        ->pressAndWaitFor('Review shopping list')
        ->click('summary[aria-label="Shopping list items"]')
        ->assertSee('For Satay chicken')
        ->click('Budget and estimate')
        ->type('input[aria-label="Shopping budget"]', '100')
        ->pressAndWaitFor('Save budget')
        ->assertSee('$100.00')
        ->type('input[aria-label="New shopping item"]', 'Hand soap')
        ->type('input[aria-label="New item quantity"]', '1')
        ->pressAndWaitFor('Add')
        ->assertSee('Household')
        ->assertPresent('button[aria-label="Edit Hand soap"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Chicken breast as bought"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Coconut milk as bought"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Hand soap as bought"]')
        ->pressAndWaitFor('Complete shop')
        ->assertSee('Completed')
        ->type('input[aria-label="Actual order total"]', '31.40')
        ->pressAndWaitFor('Record order')
        ->assertSee('$31.40')
        ->assertNoJavaScriptErrors();
});

it('automatically prepares ingredients for a custom meal without manual reconstruction', function () {
    $workspace = browserShoppingWorkspace();
    $team = $workspace['user']->currentTeam;
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        today(),
        MealSlotKind::Lunch,
        $team->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Pulled pork rolls',
        servings: 2,
    );
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertDontSee('needs structured ingredients')
        ->assertNotPresent('textarea[aria-label="Ingredients for Pulled pork rolls"]')
        ->click('summary[aria-label="Shopping list items"]')
        ->click('button[aria-label="List view"]')
        ->click('button[aria-label="Edit Pulled pork rolls ingredients"]')
        ->assertPresent('input[aria-label="Pulled pork rolls ingredients name"]')
        ->assertSee('For Pulled pork rolls')
        ->assertNoJavaScriptErrors();
});

it('keeps the shopping editor usable at a narrow viewport', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Shopping list')
        ->assertScript(
            '() => Array.from(document.querySelector(\'[data-sidebar="trigger"]\')?.closest(\'header\')?.querySelectorAll(\'a\') ?? []).some((link) => link.textContent?.trim() === \'Back to plan\')',
        )
        ->assertSee('Full shopping list')
        ->assertScript('() => document.querySelector(\'summary[aria-label="Shopping list items"]\')?.parentElement?.open === false')
        ->click('summary[aria-label="Shopping list items"]')
        ->assertSee('Meat & Seafood')
        ->assertSee('Pantry')
        ->assertSee('Satay chicken')
        ->assertSee('Pantry review')
        ->assertPresent('input[aria-label="New shopping item"]')
        ->assertPresent('button[aria-label="Actions for Chicken breast"]')
        ->press('Review pantry')
        ->assertSee('I have this')
        ->assertScript('() => Array.from(document.querySelectorAll("button")).filter((button) => button.textContent.includes("I have this")).every((button) => button.getBoundingClientRect().height >= 36)')
        ->press('Show all items')
        ->click('button[aria-label="Table view"]')
        ->assertPresent('[data-slot="table"]')
        ->assertPresent('button[aria-label="Actions for Chicken breast"]')
        ->assertScript('() => Array.from(document.querySelectorAll("[data-testid=shopping-item-checkbox]")).every((item) => item.getBoundingClientRect().width >= 20 && item.getBoundingClientRect().height >= 20)')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('shows a structured current shopping recap instead of a stale planning message', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->click('summary[aria-label="Plan recap"]')
        ->assertSee('2 items are on the current list')
        ->assertSee('2 remain to buy')
        ->assertDontSee('recipes are still preparing')
        ->assertNoJavaScriptErrors();
});

it('shows shopping-list progress once every recipe is ready', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list->update([
        'generation_status' => ShoppingListGenerationStatus::Processing,
        'generation_started_at' => now(),
    ]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Building your shopping list')
        ->assertSee('All recipes are ready. Chef is combining ingredients and quantities now.')
        ->assertDontSee('Preparing your recipes')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('renders recipe-provenanced plan-generated rows on desktop', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list->items()->update(['source_kind' => ShoppingListItemSourceKind::PlanGenerated]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Shopping list')
        ->click('summary[aria-label="Shopping list items"]')
        ->click('button[aria-label="List view"]')
        ->assertSee('For Satay chicken')
        ->assertPresent('button[aria-label="Edit Chicken breast"]')
        ->assertNoJavaScriptErrors();
});

it('shows a safe shopping-generation failure and retries it', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list->update([
        'generation_status' => ShoppingListGenerationStatus::Failed,
        'generation_failure_code' => 'generation_failed',
        'generation_failure_message' => 'Chef could not prepare this shopping list. Try again.',
    ]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Chef could not finish this shopping list')
        ->assertSee('Chef could not prepare this shopping list. Try again.')
        ->pressAndWaitFor('Retry')
        ->assertSee('Full shopping list')
        ->click('summary[aria-label="Shopping list items"]')
        ->click('button[aria-label="List view"]')
        ->assertPresent('button[aria-label="Edit Chicken breast"]')
        ->assertNoJavaScriptErrors();
});

it('directs stale safety context back to plan review on desktop and narrow screens', function () {
    $workspace = browserShoppingWorkspace();
    $workspace['plan']->refresh()->update(['confirmed_safety_context_hash' => null]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Review the plan’s safety details')
        ->assertSee('Review plan safety')
        ->assertNoJavaScriptErrors();

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Review the plan’s safety details')
        ->assertSee('Review plan safety')
        ->assertNoJavaScriptErrors();
});

it('retries the shared plan conversation from shopping', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $message = app(CreateUserMessage::class)->handle(
        $workspace['plan']->conversations()->firstOrFail(),
        $workspace['user'],
        'Please retry the shopping update.',
        (string) Str::uuid(),
        true,
        true,
    );
    $message->update([
        'response_status' => MessageResponseStatus::Failed,
        'response_error' => 'Chef could not finish that response.',
    ]);
    ChefAgent::fake(['Recovered the shopping conversation.'])->preventStrayPrompts();
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->click('summary[aria-label="Plan recap"]')
        ->assertPresent('button[aria-label="Retry failed shopping message"]')
        ->click('button[aria-label="Retry failed shopping message"]')
        ->assertSee('Recovered the shopping conversation.')
        ->assertNoJavaScriptErrors();

    expect($message->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($message->response()->count())->toBe(1);
});

it('offers just-in-time Woolworths connection without overflowing a narrow screen', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Woolworths cart')
        ->assertSee('Connect Woolworths when the list is ready')
        ->assertSee('Chef’s model is not attached during sign-in')
        ->assertPresent('button:has-text("Connect Woolworths")')
        ->assertScript('() => { const cart = document.querySelector("#woolworths-cart-heading"); const items = document.querySelector(\'summary[aria-label="Shopping list items"]\'); return Boolean(cart && items && (cart.compareDocumentPosition(items) & Node.DOCUMENT_POSITION_FOLLOWING)); }')
        ->assertScript('() => { const section = document.querySelector("#woolworths-cart-heading")?.closest("section"); if (!section) return false; const style = getComputedStyle(section); return style.borderTopWidth === "0px" && style.borderBottomWidth === "0px"; }')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('keeps human login separate from cart mutation in the recording-disabled view', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    $session = app(StartRetailerConnection::class)->handle(
        $workspace['user']->currentTeam,
        $workspace['user'],
    );
    $this->actingAs($workspace['user']);

    visit(route('browser-sessions.authenticate.show', $session))
        ->resize(390, 844)
        ->assertSee('Sign in to Woolworths')
        ->assertSee('The Chef model is not attached during sign-in')
        ->assertSee('Recording disabled')
        ->assertSee('It will not start filling the cart yet')
        ->assertPresent('iframe[title="Woolworths secure sign-in"][allow="clipboard-read; clipboard-write"]')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('requires explicit product and safety review before preparing a cart', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    config()->set('automation.cart_mutation_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    putenv('WOOLWORTHS_CART_MUTATION_ENABLED=true');
    $session = app(StartRetailerConnection::class)->handle(
        $workspace['user']->currentTeam,
        $workspace['user'],
    );
    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);
    $discovery = app(RetailerProductDiscovery::class);
    expect($discovery)->toBeInstanceOf(FakeRetailerProductDiscovery::class);
    $discovery->returnAmbiguousCandidates = true;
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Next step')
        ->assertSee('Find products for your list')
        ->assertSee('Chef will search Woolworths for 2 items before opening your authenticated cart.')
        ->assertSee('Connected')
        ->assertSee('Full shopping list')
        ->assertScript('() => document.querySelector(\'summary[aria-label="Shopping list items"]\')?.parentElement?.open === false')
        ->assertDontSee('Complete shop')
        ->assertDontSee('I reviewed the household safety context and this product plan.')
        ->assertDontSee('Prepare Woolworths cart')
        ->click('Find Woolworths products')
        ->assertSee('Choose a product for Chicken breast')
        ->assertSee('2 decisions remain')
        ->click('button[aria-label="Choose Chicken breast for Chicken breast"]')
        ->assertSee('Choose a product for Coconut milk')
        ->assertSee('1 decision remains')
        ->click('button[aria-label="Choose Coconut milk for Coconut milk"]')
        ->assertSee('Prepare your Woolworths cart')
        ->assertSee('All 2 products are matched')
        ->click('[data-slot="checkbox"][aria-label="Confirm exact Woolworths product plan review"]')
        ->click('[data-slot="checkbox"][aria-label="Confirm cart product plan safety review"]')
        ->assertScript('() => Array.from(document.querySelectorAll("button")).find((button) => button.textContent.includes("Prepare Woolworths cart"))?.disabled', false)
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();

    expect($list->automationRuns)->toHaveCount(0);
});

it('turns a catalogue outage into one compact retry instead of a manual item list', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    config()->set('automation.cart_mutation_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    putenv('WOOLWORTHS_CART_MUTATION_ENABLED=true');
    $session = app(StartRetailerConnection::class)->handle(
        $workspace['user']->currentTeam,
        $workspace['user'],
    );
    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);
    $discovery = app(RetailerProductDiscovery::class);
    expect($discovery)->toBeInstanceOf(FakeRetailerProductDiscovery::class);
    $discovery->fail = true;
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->click('Find Woolworths products')
        ->assertSee('Retry Woolworths product matching')
        ->assertSee('You do not need to match every item manually.')
        ->assertSee('Try product search again')
        ->assertDontSee('No confident Woolworths candidate was found')
        ->assertDontSee('Prepare Woolworths cart')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('keeps a failed MFA probe in human control and verifies it before returning to Shopping', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->authenticationFailureReason = 'Woolworths is waiting for MFA.';
    $session = app(StartRetailerConnection::class)->handle(
        $workspace['user']->currentTeam,
        $workspace['user'],
    );
    $session->update([
        'metadata' => [
            ...($session->metadata ?? []),
            'fake_authentication_failures_remaining' => 1,
            'return_shopping_list_id' => $list->id,
        ],
    ]);
    $this->actingAs($workspace['user']);

    $page = visit(route('browser-sessions.authenticate.show', $session))
        ->resize(390, 844)
        ->pressAndWaitFor('I’ve signed in')
        ->assertSee('Woolworths is waiting for MFA.')
        ->assertSee('Recording disabled')
        ->assertNoJavaScriptErrors();

    $page->pressAndWaitFor('I’ve signed in')
        ->assertSee('Woolworths connected')
        ->assertSee('Find products for your list')
        ->assertDontSee('Prepare Woolworths cart')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('shows a recoverable retry when the protected-cart probe times out', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    config()->set('automation.connection_enabled', true);
    putenv('WOOLWORTHS_CONNECTION_ENABLED=true');
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $session = app(StartRetailerConnection::class)->handle(
        $workspace['user']->currentTeam,
        $workspace['user'],
    );
    $executor->executeFailure = new RuntimeException('Fake browser worker timeout.');
    $this->actingAs($workspace['user']);

    visit(route('browser-sessions.authenticate.show', $session))
        ->resize(390, 844)
        ->pressAndWaitFor('I’ve signed in')
        ->assertSee('Chef could not verify the protected Woolworths cart yet')
        ->assertSee('Your secure session is still open')
        ->assertSee('I’ve signed in')
        ->assertNoJavaScriptErrors();
});

/**
 * @return array{
 *     user: User,
 *     plan: MealPlan,
 *     list: ShoppingList,
 *     run: RetailerOrderRun,
 *     slot: array<string, mixed>,
 * }
 */
function browserRetailerOrderRun(array $runOverrides = []): array
{
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
    );
    $woolworths = Retailer::query()->firstOrCreate(
        ['slug' => 'woolworths'],
        ['name' => 'Woolworths'],
    );
    $connection = RetailerConnection::factory()->create([
        'team_id' => $workspace['user']->currentTeam->id,
        'retailer_id' => $woolworths->id,
        'owner_user_id' => $workspace['user']->id,
    ]);
    $slot = [
        'id' => 'slot-browser-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];
    $cartChecksum = hash('sha256', 'browser-order-cart');

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $workspace['user']->currentTeam->id,
        'shopping_list_id' => $list->id,
        'shopping_list_revision_id' => $list->revisions()
            ->where('revision', $list->revision)
            ->firstOrFail()
            ->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $workspace['user']->id,
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
        'fulfilment_type' => 'delivery',
        'fulfilment_options' => [
            'type' => 'delivery',
            'slots' => [$slot],
        ],
        'fulfilment_options_expires_at' => now()->addMinutes(20),
        'cart_checksum' => $cartChecksum,
        'expires_at' => now()->addHour(),
        ...$runOverrides,
    ]);

    RetailerOrderRunItem::factory()->create([
        'team_id' => $workspace['user']->currentTeam->id,
        'retailer_order_run_id' => $run->id,
        'shopping_list_item_id' => null,
        'position' => 1,
        'status' => RetailerOrderRunItemStatus::Matched,
        'requirement_snapshot' => [
            'name' => 'Chicken breast',
            'quantity' => 500,
            'unit' => 'g',
        ],
        'matched_product' => [
            'external_id' => 'browser-chicken',
            'product_name' => 'Chicken breast',
            'quantity' => 1,
        ],
        'resolved_at' => now(),
    ]);

    return [
        'user' => $workspace['user'],
        'plan' => $workspace['plan'],
        'list' => $list,
        'run' => $run->refresh(),
        'slot' => $slot,
    ];
}

it('lists fulfilment slots when a retailer order run awaits selection', function () {
    $fixture = browserRetailerOrderRun();
    $this->actingAs($fixture['user']);

    visit(route('meal-plans.shopping.show', $fixture['plan']))->on()->desktop()
        ->assertSee('Choose a delivery time')
        ->assertSee('Tomorrow 6–8pm')
        ->assertSee('Use this delivery time')
        ->assertNoJavaScriptErrors();
});

it('names delivery, slot, and default card before order confirmation', function () {
    $fixture = browserRetailerOrderRun([
        'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
        'selected_slot' => [
            'id' => 'slot-browser-1',
            'label' => 'Tomorrow 6–8pm',
            'starts_at' => '2026-07-23T18:00:00+10:00',
            'ends_at' => '2026-07-23T20:00:00+10:00',
            'fee' => 0.0,
        ],
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
    ]);
    $this->actingAs($fixture['user']);

    visit(route('meal-plans.shopping.show', $fixture['plan']))->on()->desktop()
        ->assertSee('Confirm Woolworths order')
        ->assertSee('Delivery')
        ->assertSee('Tomorrow 6–8pm')
        ->assertSee('default card on file')
        ->assertSee('Confirm and place order')
        ->assertNoJavaScriptErrors();
});

it('shows placement verification when Chef needs an order number or ack', function () {
    $fixture = browserRetailerOrderRun([
        'status' => RetailerOrderRunStatus::AwaitingPlacementVerification,
        'selected_slot' => [
            'id' => 'slot-browser-1',
            'label' => 'Tomorrow 6–8pm',
        ],
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'browser-fp',
        ],
        'confirmation_fingerprint' => 'browser-fp',
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
    ]);
    $this->actingAs($fixture['user']);

    visit(route('meal-plans.shopping.show', $fixture['plan']))->on()->desktop()
        ->assertSee('Verify Woolworths placement')
        ->assertPresent('input#retailer-order-reference-'.$fixture['run']->id)
        ->assertSee('I confirm the Woolworths order was placed')
        ->assertSee('Save verification')
        ->assertNoJavaScriptErrors();
});

it('offers merge, replace, and cancel for a retailer cart decision', function () {
    $fixture = browserRetailerOrderRun([
        'status' => RetailerOrderRunStatus::AwaitingCartDecision,
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
        'fulfilment_type' => null,
        'cart_checksum' => null,
    ]);
    $this->actingAs($fixture['user']);

    visit(route('meal-plans.shopping.show', $fixture['plan']))->on()->desktop()
        ->assertSee('Woolworths already has a cart')
        ->assertSee('Merge carts')
        ->assertSee('Replace existing cart')
        ->assertSee('Cancel order run')
        ->assertNoJavaScriptErrors();
});
