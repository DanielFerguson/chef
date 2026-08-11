<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Retailers\RuntimeRetailerMutationCircuitBreaker;
use App\Actions\Retailers\StartRetailerConnection;
use App\Actions\Retailers\VerifyRetailerConnection;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\BasketRunStatus;
use App\Enums\MealSlotKind;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\BuildGroceryPlanJob;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\ReplaceBasketJob;
use App\Models\BasketRun;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Laravel\Ai\Responses\Data\ToolCall;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

function stabilizeHappyPathPage(AwaitableWebpage|Webpage $page): void
{
    if ($page instanceof AwaitableWebpage) {
        $page->page()->addStyleTag('* { transition: none !important; animation: none !important; }');

        return;
    }

    $page->script(<<<'JS'
        () => {
            const style = document.createElement('style');
            style.textContent = '* { transition: none !important; animation: none !important; }';
            document.head.append(style);
        }
        JS);
}

/**
 * @return array{
 *     user: User,
 *     team: Team,
 *     plan: MealPlan,
 *     run: BasketRun,
 *     connection: RetailerConnection|null,
 *     gateway: FakeRetailerAutomationGateway
 * }
 */
function retailerHappyPathWorkspace(bool $connectBeforeApproval): array
{
    config()->set('retailer.features.experience', true);
    config()->set('retailer.features.discovery', false);
    config()->set('retailer.features.mutation', true);
    config()->set('retailer.features.mutation_circuit_breaker', false);
    config()->set('retailer.runtime_circuit_breaker.store', 'array');
    app(RuntimeRetailerMutationCircuitBreaker::class)->close('browser happy path');

    $user = User::factory()->create(['name' => 'Alex Cook']);
    $team = app(CreateTeamForUser::class)->handle($user, 'The Happy Kitchen');
    $gateway = new FakeRetailerAutomationGateway;
    app()->instance(RetailerAutomationGateway::class, $gateway);

    $gateway->liveViewUrl = 'about:blank';

    $connection = null;
    if ($connectBeforeApproval) {
        $connectionResult = app(StartRetailerConnection::class)->handle($team, $user, $gateway);
        $connection = app(VerifyRetailerConnection::class)->handle(
            $connectionResult['connection'],
            $user,
            $gateway,
        );
    }

    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDays(2),
        'Happy path dinners',
    );
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people,
    );
    app(ProposeMeal::class)->handle(
        $plan,
        $user,
        'Satay chicken',
        $slot,
        'A practical weeknight satay with rice.',
        30,
        18,
    );
    app(ApproveMealPlan::class)->handle($plan, $user);

    $run = $plan->basketRuns()
        ->with('groceryPlan.requirements')
        ->sole();

    if ($run->grocery_plan_id === null) {
        BuildGroceryPlanJob::dispatch($plan->id);
        $run = $run->refresh()->load('groceryPlan.requirements');
    }

    return compact('user', 'team', 'plan', 'run', 'connection', 'gateway');
}

it('keeps registration planning approval cooking and feedback on one deterministic happy path', function () {
    $page = visit(route('register'))->on()->desktop()
        ->assertSee('Create an account')
        ->type('#name', 'Happy Cook')
        ->type('#email', 'happy-cook@example.test')
        ->type('#password', 'password')
        ->type('#password_confirmation', 'password')
        ->press('Create account')
        ->assertPathIs('/dashboard')
        ->assertSee('Welcome to Chef');

    $user = User::query()->where('email', 'happy-cook@example.test')->firstOrFail();
    $team = $user->currentTeam()->with('people')->firstOrFail();
    $person = $team->people->sole();
    $request = 'Please remember beyond this plan that our family dislikes olives, and plan satay chicken for tonight.';

    ChefAgent::fake([
        new ToolCall('inspect-plan', 'InspectMealPlan', []),
        new ToolCall('remember-olives', 'RecordHouseholdPreference', [
            'scope' => 'family',
            'subject' => 'olives',
            'sentiment' => 'dislike',
            'provenance' => 'stated',
            'strength' => 4,
            'evidence_quote' => 'our family dislikes olives',
        ]),
        new ToolCall('create-slot', 'CreatePlanMealSlot', [
            'date' => today()->toDateString(),
            'kind' => 'dinner',
            'participant_ids' => [$person->id],
        ]),
        fn () => new ToolCall('create-proposal', 'CreateMealProposal', [
            'title' => 'Satay chicken',
            'meal_slot_id' => MealSlot::query()->sole()->id,
            'summary' => 'A practical weeknight satay with rice.',
            'estimated_minutes' => 30,
            'estimated_cost' => 18,
        ]),
        'I remembered the family olive preference and added satay chicken for review.',
    ])->preventStrayPrompts();

    $page->press('Start a plan')
        ->assertSee('Who are we feeding')
        ->type('textarea[aria-label="Message Chef"]', $request)
        ->click('[data-testid="send-message"]')
        ->assertSee('I remembered the family olive preference')
        ->assertSee('Satay chicken')
        ->assertSee('Your plan is ready to approve')
        ->assertNoSmoke();

    stabilizeHappyPathPage($page);
    $page->assertNoAccessibilityIssues()
        ->press('Approve plan & prepare recipes')
        ->assertSee('Plan and recipes are ready')
        ->click('button[aria-label="Calendar"]')
        ->assertSee('Meal calendar')
        ->assertSee('Satay chicken')
        ->click('button[aria-label="List"]')
        ->assertSee('Satay chicken')
        ->click('Today')
        ->assertPathIs('/dashboard')
        ->assertSee('Satay chicken')
        ->press('Start cooking')
        ->assertSee('Step 1 of 1')
        ->assertSee('Prepare and cook Satay chicken.')
        ->click('Finish and record outcome')
        ->press('Save outcome')
        ->assertSee('How was it for everyone?')
        ->click('button[aria-label="like for Happy Cook"]')
        ->type('textarea[aria-label="Feedback notes for Happy Cook"]', 'Easy, bright, and worth repeating.')
        ->press("Save Happy Cook's feedback")
        ->assertSee('Feedback saved')
        ->click('Back to Today')
        ->assertSee('cooked recorded')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();

    $plan = $team->mealPlans()->with('plannedMeals.recipeVersion', 'outcomes.feedback')->sole();
    $preference = $team->preferences()->whereNull('person_id')->sole();
    $meal = $plan->plannedMeals->sole();
    $outcome = $plan->outcomes->sole();

    expect($preference->subject)->toBe('olives')
        ->and($preference->evidence_quote)->toBe('our family dislikes olives')
        ->and($plan->planning_confirmed_at)->not->toBeNull()
        ->and($meal->recipeVersion)->not->toBeNull()
        ->and($outcome->status->value)->toBe('cooked')
        ->and($outcome->feedback)->toHaveCount(1)
        ->and($outcome->feedback->sole()->notes)->toBe('Easy, bright, and worth repeating.');

    $this->actingAs($user);
    visit(route('dashboard'))->on()->iPhone15()
        ->assertSee('Satay chicken')
        ->assertSee('cooked recorded')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();
})->group('happy-path');

it('requires explicit standing consent in Live View before resuming the first Coles basket', function () {
    $workspace = retailerHappyPathWorkspace(false);
    $this->actingAs($workspace['user']);

    $page = visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Connect Coles to continue')
        ->click('[data-testid="connect-coles"]')
        ->assertSee('Review before granting standing consent')
        ->assertPresent('iframe[title="Secure Coles sign-in"]')
        ->assertDisabled('Allow Chef & continue')
        ->assertNoSmoke();

    stabilizeHappyPathPage($page);
    $page->assertNoAccessibilityIssues()
        ->check('input[type="checkbox"]')
        ->assertEnabled('Allow Chef & continue')
        ->press('Allow Chef & continue')
        ->assertDontSee('Review before granting standing consent')
        ->assertSee('Preparing your Coles basket')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();

    $connection = RetailerConnection::query()->whereBelongsTo($workspace['team'])->sole();
    $run = $workspace['run']->refresh();

    expect($connection->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($connection->grants()->whereNull('revoked_at')->count())->toBe(1)
        ->and($connection->active_session_id)->toBeNull()
        ->and($run->retailer_connection_id)->toBe($connection->id)
        ->and($run->status)->toBe(BasketRunStatus::DiscoveringProducts);

    visit(route('meal-plans.show', $workspace['plan']))->on()->iPhone15()
        ->assertSee('Preparing your Coles basket')
        ->assertSee('You can leave this page.')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();
})->group('happy-path');

it('runs standing-consent discovery and replacement through jobs then preserves review controls', function () {
    $workspace = retailerHappyPathWorkspace(true);
    $this->actingAs($workspace['user']);

    $page = visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Preparing your Coles basket')
        ->assertSee('You can leave this page.');

    $requirement = $workspace['run']->groceryPlan->requirements->sole();
    $workspace['gateway']->searchCandidates = [
        [
            'requirement_id' => $requirement->id,
            'origin_host' => 'www.coles.com.au',
            'sku' => 'coles-satay-500',
            'title' => 'Coles Satay Chicken Ingredients 500g',
            'brand' => 'Coles',
            'semantic_key' => 'satay chicken ingredients',
            'product_path' => '/product/coles-satay-chicken-ingredients-500',
            'pack_quantity' => 500,
            'pack_unit' => 'g',
            'price_cents' => 450,
            'available' => true,
            'restricted_product' => false,
            'label_evidence' => [],
        ],
        [
            'requirement_id' => $requirement->id,
            'origin_host' => 'www.coles.com.au',
            'sku' => 'brand-satay-750',
            'title' => 'Kitchen Brand Satay Chicken Ingredients 750g',
            'brand' => 'Kitchen Brand',
            'semantic_key' => 'satay chicken ingredients',
            'product_path' => '/product/kitchen-brand-satay-chicken-ingredients-750',
            'pack_quantity' => 750,
            'pack_unit' => 'g',
            'price_cents' => 650,
            'available' => true,
            'restricted_product' => false,
            'label_evidence' => [],
        ],
    ];
    $workspace['gateway']->basketLines = [[
        'sku' => 'existing-apples',
        'title' => 'Apples',
        'absolute_quantity' => 2,
        'unit_price_cents' => 100,
        'line_price_cents' => 200,
    ]];
    config()->set('retailer.features.discovery', true);

    DiscoverRetailerProductsJob::dispatch($workspace['run']->id);

    $run = $workspace['run']->refresh();
    expect($run->status)->toBe(BasketRunStatus::RevalidatingProducts);

    ReplaceBasketJob::dispatch($run->id);
    $run->refresh();

    expect($run->status)->toBe(BasketRunStatus::Ready);

    $page->navigate(route('dashboard'))
        ->assertSee('Satay chicken')
        ->navigate(route('meal-plans.show', $workspace['plan']))
        ->refresh()
        ->assertSee('Your Coles basket is ready')
        ->click('View basket')
        ->assertSee('confirmed from the actual Coles basket')
        ->assertSee('Coles Satay Chicken Ingredients 500g')
        ->assertSee('$4.50')
        ->click('Why this pack')
        ->assertSee('smallest otherwise-valid pack')
        ->assertSee('Kitchen Brand Satay Chicken Ingredients 750g')
        ->click('Prefer next time')
        ->assertSee('Saved for next time')
        ->assertSee('Restore previous basket')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues()
        ->click('Review in Coles')
        ->assertSee('checkout, fulfilment, and payment are entirely under your control')
        ->assertPresent('iframe[title="Review your Coles basket"]')
        ->press('Close review')
        ->assertDontSee('Automation is stopped.')
        ->assertNoSmoke();

    $run->refresh();
    $item = $run->items()->sole();

    expect($run->snapshots()->count())->toBe(2)
        ->and($item->verified_at)->not->toBeNull()
        ->and($workspace['gateway']->basketLines)->toHaveCount(1)
        ->and($workspace['gateway']->basketLines[0]['sku'])->toBe('coles-satay-500')
        ->and($workspace['team']->retailerProductPreferences()->sole()->sku)->toBe('brand-satay-750')
        ->and($workspace['connection']?->refresh()->active_session_id)->toBeNull();

    visit(route('basket-runs.show', $run))->on()->iPhone15()
        ->assertSee('Basket ready')
        ->assertSee('Coles Satay Chicken Ingredients 500g')
        ->assertSee('Review in Coles')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();
})->group('happy-path');
