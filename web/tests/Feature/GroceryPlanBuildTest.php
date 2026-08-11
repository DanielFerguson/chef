<?php

use App\Actions\Baskets\StartBasketRunForApprovedPlan;
use App\Actions\Groceries\BuildGroceryPlan;
use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Recipes\BuildMealPlanRecipeDraftRequest;
use App\Actions\Recipes\MaterializeMealPlanRecipes;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\MealSlotKind;
use App\Enums\MessageRole;
use App\Jobs\BuildGroceryPlanJob;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, team: Team, plan: MealPlan, source_message: Message} */
function groceryBuildWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Grocery plan family');
    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDay(),
        'Grocery plan week',
    );
    $conversation = $plan->conversations()->firstOrFail();
    $sourceMessage = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Keep the budget low, use leftovers, and make each meal quick.',
    ]);

    foreach (range(0, 1) as $offset) {
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
            $offset === 0 ? 'Penne bake' : 'Pasta salad',
            $slot,
            'A practical pasta dinner.',
            30,
            12,
            $offset === 0 ? $sourceMessage : null,
        );
    }

    return [
        'user' => $user,
        'team' => $team,
        'plan' => $plan,
        'source_message' => $sourceMessage,
    ];
}

/**
 * @param  array<string, mixed>  $meal
 * @return array<string, mixed>
 */
function groceryRecipeDraft(array $meal, int $index): array
{
    return [
        'planned_meal_id' => $meal['planned_meal_id'],
        'title' => $meal['title'],
        'summary' => $meal['summary'],
        'servings' => 2,
        'prep_minutes' => 10,
        'cook_minutes' => 20,
        'ingredients' => [
            [
                'name' => 'Pasta',
                'quantity' => $index === 0 ? 200 : 0.5,
                'unit' => $index === 0 ? 'g' : 'kg',
                'preparation' => 'dry',
                'optional' => false,
            ],
            [
                'name' => 'Water',
                'quantity' => 1,
                'unit' => 'l',
                'preparation' => null,
                'optional' => false,
            ],
            [
                'name' => 'Fresh basil',
                'quantity' => 1,
                'unit' => 'bunch',
                'preparation' => null,
                'optional' => true,
            ],
            [
                'name' => 'Salt',
                'quantity' => 1,
                'unit' => 'tsp',
                'preparation' => null,
                'optional' => false,
            ],
        ],
        'steps' => [[
            'instruction' => 'Prepare and cook the meal.',
            'timer_minutes' => 20,
        ]],
        'equipment' => ['Saucepan'],
        'notices' => [],
        'storage_guidance' => 'Refrigerate leftovers promptly.',
    ];
}

it('uses source-linked riff excerpts in the one atomic recipe request', function () {
    Queue::fake();
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $request = app(BuildMealPlanRecipeDraftRequest::class)->handle(
        $workspace['plan']->refresh(),
    );
    $context = collect($request->conversationContext)
        ->firstWhere('message_id', $workspace['source_message']->id);

    expect($context)->not->toBeNull()
        ->and($context['content'])->toContain('budget low')
        ->and($context['sources'])->toContain('proposal_source')
        ->and($context['sources'])->toContain('recent_plan_instruction');
});

it('scales and conservatively aggregates recipe ingredients while preserving every source', function () {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft(array_map(
                fn (array $meal, int $index): array => groceryRecipeDraft($meal, $index),
                $request->meals,
                array_keys($request->meals),
            ));
        }
    });

    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    Queue::assertPushed(BuildGroceryPlanJob::class, 1);
    $workspace['plan']->plannedMeals()->update(['servings' => 4]);

    $groceryPlan = app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());
    $pasta = $groceryPlan->requirements->firstWhere('normalized_name', 'pasta');
    $salt = $groceryPlan->requirements->firstWhere('normalized_name', 'salt');

    expect($groceryPlan->status)->toBe(GroceryPlanStatus::Ready)
        ->and($pasta)->not->toBeNull()
        ->and($pasta->quantity)->toBe(1400.0)
        ->and($pasta->unit)->toBe('g')
        ->and($pasta->sources)->toHaveCount(2)
        ->and($salt)->not->toBeNull()
        ->and($salt->quantity_unknown)->toBeTrue()
        ->and($salt->quantity)->toBeNull()
        ->and($groceryPlan->requirements->pluck('normalized_name'))->not->toContain('water')
        ->and($groceryPlan->requirements->pluck('normalized_name'))->not->toContain('fresh basil');

    $samePlan = app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());
    expect($samePlan->id)->toBe($groceryPlan->id)
        ->and($workspace['plan']->basketRuns()->sole()->status)->toBe(BasketRunStatus::WaitingForConnection);

    $workspace['plan']->plannedMeals()->firstOrFail()->update(['servings' => 2]);
    $nextPlan = app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());

    expect($nextPlan->version)->toBe(2)
        ->and($nextPlan->id)->not->toBe($groceryPlan->id)
        ->and($groceryPlan->refresh()->status)->toBe(GroceryPlanStatus::Superseded)
        ->and($groceryPlan->superseded_at)->not->toBeNull();
});

it('keeps approval and basket-run creation idempotent after recipes materialise', function () {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    $firstRun = $workspace['plan']->basketRuns()->sole();

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft(array_map(
                fn (array $meal, int $index): array => groceryRecipeDraft($meal, $index),
                $request->meals,
                array_keys($request->meals),
            ));
        }
    });
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    app(ApproveMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($workspace['plan']->basketRuns()->count())->toBe(1)
        ->and($workspace['plan']->basketRuns()->sole()->is($firstRun))->toBeTrue();
});

it('attaches an existing grocery plan when a basket run is created after the build', function () {
    Queue::fake();
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $groceryPlan = app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());

    config()->set('retailer.features.experience', true);
    $run = app(StartBasketRunForApprovedPlan::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
    );

    expect($run)->not->toBeNull()
        ->and($run->grocery_plan_id)->toBeNull();

    $replayed = app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());

    expect($replayed->is($groceryPlan))->toBeTrue()
        ->and($run->refresh()->grocery_plan_id)->toBe($groceryPlan->id)
        ->and($run->status)->toBe(BasketRunStatus::WaitingForConnection);
});

it('preserves reauthentication while grocery requirements finish building', function () {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft(array_map(
                fn (array $meal, int $index): array => groceryRecipeDraft($meal, $index),
                $request->meals,
                array_keys($request->meals),
            ));
        }
    });
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $run = $workspace['plan']->basketRuns()->sole();
    $run->update(['status' => BasketRunStatus::ReauthenticationRequired]);

    app(BuildGroceryPlan::class)->handle($workspace['plan']->refresh());

    expect($run->refresh()->grocery_plan_id)->not->toBeNull()
        ->and($run->status)->toBe(BasketRunStatus::ReauthenticationRequired);
});

it('rejects a stale database revision even when the caller holds an approved snapshot', function () {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);

    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            return new MealPlanRecipeDraft(array_map(
                fn (array $meal, int $index): array => groceryRecipeDraft($meal, $index),
                $request->meals,
                array_keys($request->meals),
            ));
        }
    });
    app(MaterializeMealPlanRecipes::class)->handle($workspace['plan']->refresh());
    $approvedSnapshot = $workspace['plan']->refresh();
    MealPlan::query()->whereKey($approvedSnapshot)->update(['derived_data_stale_at' => now()]);

    expect(fn () => app(BuildGroceryPlan::class)->handle($approvedSnapshot))
        ->toThrow(ValidationException::class)
        ->and($workspace['plan']->groceryPlans()->count())->toBe(0);
});

it('refuses to start a basket run from a stale approved plan snapshot', function () {
    Queue::fake();
    config()->set('retailer.features.experience', false);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    $approvedSnapshot = $workspace['plan']->refresh();
    MealPlan::query()->whereKey($approvedSnapshot)->update(['derived_data_stale_at' => now()]);
    config()->set('retailer.features.experience', true);

    expect(fn () => app(StartBasketRunForApprovedPlan::class)->handle(
        $approvedSnapshot,
        $workspace['user'],
    ))->toThrow(ValidationException::class)
        ->and($workspace['plan']->basketRuns()->count())->toBe(0);
});

it('marks an active basket run failed when grocery requirement building exhausts its attempts', function () {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $workspace = groceryBuildWorkspace();
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    $run = $workspace['plan']->basketRuns()->sole();
    $run->update(['status' => BasketRunStatus::WaitingForRecipes]);

    (new BuildGroceryPlanJob($workspace['plan']->id))
        ->failed(new RuntimeException('provider secret must not persist'));

    expect($run->refresh()->status)->toBe(BasketRunStatus::Failed)
        ->and($run->failure_code)->toBe('grocery_plan_build_failed')
        ->and($run->failure_message)->toBe('Chef could not finish preparing grocery requirements. Try preparing the basket again.')
        ->and($run->failure_message)->not->toContain('provider secret')
        ->and($run->claim_token)->toBeNull()
        ->and($run->claimed_at)->toBeNull();
});
