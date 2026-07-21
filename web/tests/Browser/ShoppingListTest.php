<?php

use App\Actions\Automation\StartCartPreparation;
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
use App\Automation\Testing\FakeComputerExecutor;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListGenerationStatus;
use App\Enums\ShoppingListItemSourceKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        ->hover('[data-testid="complete-shop-tooltip-trigger"]')
        ->assertSee('Mark the 2 remaining items as bought to complete this shop.')
        ->click('button[aria-label="List view"]')
        ->assertPresent('button[aria-label="Edit Chicken breast"]')
        ->assertPresent('button[aria-label="Edit Coconut milk"]')
        ->pressAndWaitFor('Back to plan')
        ->assertSee('Review shopping list')
        ->pressAndWaitFor('Review shopping list')
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

it('renders recipe-provenanced plan-generated rows on desktop', function () {
    $workspace = browserShoppingWorkspace();
    $list = app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $list->items()->update(['source_kind' => ShoppingListItemSourceKind::PlanGenerated]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Shopping list')
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
        ->assertSee('Items')
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
        ->assertPresent('iframe[title="Woolworths secure sign-in"]')
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
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Review the product plan')
        ->assertSee('0 exact product matches · 2 requiring Woolworths search')
        ->assertSee('I reviewed the household safety context and this product plan.')
        ->assertScript('() => Array.from(document.querySelectorAll("button")).find((button) => button.textContent.includes("Prepare Woolworths cart"))?.disabled', true)
        ->click('[data-slot="checkbox"][aria-label="Allow automatic Woolworths product search"]')
        ->click('[data-slot="checkbox"][aria-label="Confirm cart product plan safety review"]')
        ->assertScript('() => Array.from(document.querySelectorAll("button")).find((button) => button.textContent.includes("Prepare Woolworths cart"))?.disabled', false)
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();

    expect($list->automationRuns)->toHaveCount(0);
});

it('reviews a non-empty cart decision and reconciled normal-Woolworths handoff', function () {
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
    $connection = $session->retailerConnection->refresh();
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->cartLines = [[
        'external_product_id' => 'browser-existing-bread',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
        'unit' => 'loaf',
        'unit_price' => 4,
        'total_price' => 4,
    ]];
    $run = app(StartCartPreparation::class)->handle(
        $list,
        $list->revisions()->where('revision', $list->revision)->firstOrFail(),
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
        true,
        true,
    )->refresh();
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Woolworths already has items in this account’s cart')
        ->assertSee('Wholemeal bread × 1')
        ->assertSee('Merge carts')
        ->assertSee('Replace existing cart')
        ->pressAndWaitFor('Merge carts')
        ->assertSee('Verified cart review')
        ->assertSee('Wholemeal bread')
        ->assertSee('pre existing')
        ->assertSee('Open Woolworths cart')
        ->assertSee('normal-app cart synchronisation release trial is not yet recorded as proven')
        ->assertNoJavaScriptErrors();

    expect($run->refresh()->latestSnapshot)->not->toBeNull();
});

it('lets the connection owner pause for recording-disabled manual takeover', function () {
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
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $list,
        $list->revisions()->where('revision', $list->revision)->firstOrFail(),
        $session->retailerConnection->refresh(),
        $workspace['user'],
        (string) Str::uuid(),
        true,
        true,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Pause and take over')
        ->pressAndWaitFor('Pause and take over')
        ->assertSee('You control the Woolworths cart')
        ->assertSee('Its model is disconnected while you inspect or edit the cart')
        ->assertSee('Recording disabled')
        ->assertPresent('iframe[title="Manual Woolworths cart control"]')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->pressAndWaitFor('Reconcile and resume')
        ->assertSee('queued')
        ->assertNoJavaScriptErrors();

    expect($run->refresh()->status->value)->toBe('queued');
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
        ->assertSee('Prepare Woolworths cart')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('shows bounded progress and lets the owner cancel an active run', function () {
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
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $list,
        $list->revisions()->where('revision', $list->revision)->firstOrFail(),
        $session->retailerConnection->refresh(),
        $workspace['user'],
        (string) Str::uuid(),
        true,
        true,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('checking connection')
        ->assertSee('0 of 2 items')
        ->assertSee('Chef verifies the remote cart after every item')
        ->pressAndWaitFor('Cancel run')
        ->assertSee('cancelled')
        ->assertNoJavaScriptErrors();

    expect($run->refresh()->status->value)->toBe('cancelled');
});

it('surfaces bot detection as a calm intervention before cancellation', function () {
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
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->cartBotDetected = true;
    $run = app(StartCartPreparation::class)->handle(
        $list,
        $list->revisions()->where('revision', $list->revision)->firstOrFail(),
        $session->retailerConnection->refresh(),
        $workspace['user'],
        (string) Str::uuid(),
        true,
        true,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Woolworths presented bot detection')
        ->pressAndWaitFor('Cancel run')
        ->assertSee('cancelled')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();

    expect($run->refresh()->status->value)->toBe('cancelled');
});
