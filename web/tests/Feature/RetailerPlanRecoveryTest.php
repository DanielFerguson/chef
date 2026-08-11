<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Retailers\PrepareMealPlanAdjustmentDraft;
use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Retailers\SelectRetailerProducts;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\MealPlanAdjustmentDrafter;
use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Ai\Data\MealPlanAdjustmentDraftResult;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Jobs\PrepareMealPlanAdjustmentDraftJob;
use App\Jobs\ReplaceBasketJob;
use App\Jobs\SelectRetailerProductsJob;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerProductCandidate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, team: Team, plan: MealPlan, grocery_plan: GroceryPlan, requirement: GroceryRequirement, run: BasketRun, connection: RetailerConnection} */
function planRecoveryWorkspace(?int $budgetCents = null): array
{
    Queue::fake();
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Recovery family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today(), 'Recovery plan');
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people,
    );
    app(ProposeMeal::class)->handle($plan, $user, 'Original pasta', $slot, 'Pasta dinner', 30, 12);
    app(ApproveMealPlan::class)->handle($plan, $user);
    $plan->refresh();
    $effective = app(ResolveEffectiveRetailerPurchasePolicy::class)->handle($plan);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', 'recovery-plan-'.Str::uuid()),
        'recipe_fingerprint' => hash('sha256', 'recovery-recipes-'.Str::uuid()),
        'purchase_policy_snapshot' => $effective['snapshot'],
        'purchase_policy_fingerprint' => $effective['fingerprint'],
        'effective_basket_target_cents' => $budgetCents,
        'built_at' => now(),
    ]);
    $requirement = $groceryPlan->requirements()->create([
        'team_id' => $team->id,
        'status' => GroceryRequirementStatus::NeedsProduct,
        'display_name' => 'Pasta',
        'normalized_name' => 'pasta',
        'quantity' => 500,
        'unit' => 'g',
        'quantity_unknown' => false,
        'fingerprint' => hash('sha256', 'recovery-requirement-'.Str::uuid()),
        'search_queries' => ['pasta'],
        'applicable_constraints' => [],
    ]);
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'context-recovery',
        'context_lookup_hash' => hash('sha256', 'context-recovery-'.Str::uuid()),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection->id,
        'requested_by_user_id' => $user->id,
        'status' => BasketRunStatus::NeedsProduct,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'recovery-run-'.Str::uuid()),
    ]);

    return compact('user', 'team', 'plan', 'groceryPlan', 'requirement', 'run', 'connection') + [
        'grocery_plan' => $groceryPlan,
    ];
}

function validAdjustmentDrafter(): MealPlanAdjustmentDrafter
{
    return new class implements MealPlanAdjustmentDrafter
    {
        public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult
        {
            $meal = $request->meals[0];

            return new MealPlanAdjustmentDraftResult([[
                'planned_meal_id' => $meal['planned_meal_id'],
                'meal_slot_id' => $meal['meal_slot_id'],
                'title' => 'Lentil bolognese',
                'summary' => 'A coherent replacement using available ingredients.',
                'estimated_minutes' => 30,
                'estimated_cost_cents' => 900,
                'covered_requirement_ids' => array_column($request->blockedRequirements, 'requirement_id'),
            ]]);
        }
    };
}

function recoveryCandidate(GroceryRequirement $requirement, int $priceCents): RetailerProductCandidate
{
    return $requirement->candidates()->create([
        'team_id' => $requirement->team_id,
        'provider' => RetailerProvider::Coles,
        'sku' => 'recovery-pasta',
        'title' => 'Coles Penne 500g',
        'brand' => 'Coles',
        'semantic_key' => 'penne',
        'origin_host' => 'www.coles.com.au',
        'product_path' => '/product/recovery-pasta',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => $priceCents,
        'available' => true,
        'status' => RetailerCandidateStatus::Eligible,
        'rejection_codes' => [],
        'label_evidence' => [],
        'fingerprint' => hash('sha256', 'recovery-candidate-'.Str::uuid()),
        'captured_at' => now(),
    ]);
}

it('drafts one coherent unavailable-product adjustment without changing the approved plan or basket baseline', function () {
    $workspace = planRecoveryWorkspace();
    $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => validAdjustmentDrafter());

    $draft = app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run'],
        MealPlanAdjustmentKind::ProductUnavailable,
    );
    $sameDraft = app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run']->refresh(),
        MealPlanAdjustmentKind::ProductUnavailable,
    );

    expect($sameDraft->is($draft))->toBeTrue()
        ->and($workspace['plan']->adjustmentDrafts()->count())->toBe(1)
        ->and($draft->items()->count())->toBe(1)
        ->and($draft->items()->sole()->mealProposal->status)->toBe(MealProposalStatus::Pending)
        ->and($workspace['plan']->slots()->firstOrFail()->plannedMeal->title)->toBe('Original pasta')
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsPlanReview)
        ->and($workspace['run']->attention_kind)->toBe(MealPlanAdjustmentKind::ProductUnavailable->value)
        ->and($workspace['run']->attention_details['blocked_requirement_count'])->toBe(1)
        ->and($workspace['run']->mutation_started_at)->toBeNull()
        ->and($workspace['run']->snapshots()->count())->toBe(0);
});

it('rejects incomplete adjustment output and existing user proposals without silently replacing meals', function (string $failure) {
    $workspace = planRecoveryWorkspace();

    if ($failure === 'conflict') {
        app(ProposeMeal::class)->handle(
            $workspace['plan'],
            $workspace['user'],
            'User replacement',
            $workspace['plan']->slots()->firstOrFail(),
        );
        $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => validAdjustmentDrafter());
    } else {
        $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => new class implements MealPlanAdjustmentDrafter
        {
            public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult
            {
                $meal = $request->meals[0];

                return new MealPlanAdjustmentDraftResult([[
                    'planned_meal_id' => $meal['planned_meal_id'],
                    'meal_slot_id' => $meal['meal_slot_id'],
                    'title' => 'Incomplete replacement',
                    'summary' => null,
                    'estimated_minutes' => null,
                    'estimated_cost_cents' => null,
                    'covered_requirement_ids' => [],
                ]]);
            }
        });
    }

    expect(fn () => app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run'],
        MealPlanAdjustmentKind::ProductUnavailable,
    ))->toThrow(ValidationException::class)
        ->and($workspace['plan']->adjustmentDrafts()->count())->toBe(0)
        ->and($workspace['plan']->slots()->firstOrFail()->plannedMeal->title)->toBe('Original pasta');
})->with(['invalid coverage', 'conflict']);

it('pauses an over-budget selection before mutation and queues one cheaper-plan resolution', function () {
    $workspace = planRecoveryWorkspace(100);
    $workspace['requirement']->update(['status' => GroceryRequirementStatus::Pending]);
    recoveryCandidate($workspace['requirement'], 150);
    $workspace['run']->update(['status' => BasketRunStatus::SelectingProducts]);
    config()->set('retailer.features.ai_recovery', true);
    config()->set('retailer.features.mutation', true);
    config()->set('retailer.features.mutation_circuit_breaker', false);

    app(SelectRetailerProductsJob::class, ['basketRunId' => $workspace['run']->id])
        ->handle(app(SelectRetailerProducts::class));

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::PreparingResolution)
        ->and($workspace['run']->attention_kind)->toBe(MealPlanAdjustmentKind::BudgetOverrun->value)
        ->and($workspace['run']->chef_subtotal_cents)->toBe(150)
        ->and($workspace['run']->mutation_started_at)->toBeNull();
    Queue::assertPushed(PrepareMealPlanAdjustmentDraftJob::class, fn ($job) => $job->basketRunId === $workspace['run']->id
        && $job->kind === MealPlanAdjustmentKind::BudgetOverrun);
    Queue::assertNotPushed(ReplaceBasketJob::class);
});

it('allows only the Coles account owner to accept an over-budget basket and supersedes unused proposals', function () {
    $workspace = planRecoveryWorkspace(100);
    $workspace['run']->update(['chef_subtotal_cents' => 150]);
    $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => validAdjustmentDrafter());
    app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run'],
        MealPlanAdjustmentKind::BudgetOverrun,
    );
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();
    config()->set('retailer.features.mutation', true);
    config()->set('retailer.features.mutation_circuit_breaker', false);

    $this->actingAs($member)
        ->postJson('/basket-runs/'.$workspace['run']->id.'/budget-override')
        ->assertForbidden();
    $this->actingAs($workspace['user'])
        ->postJson('/basket-runs/'.$workspace['run']->id.'/budget-override')
        ->assertOk()
        ->assertJsonPath('basket_run.budget_override_cents', 150);

    $draft = $workspace['plan']->adjustmentDrafts()->sole();
    expect($workspace['run']->refresh()->budget_override_by_user_id)->toBe($workspace['user']->id)
        ->and($workspace['run']->budget_overridden_at)->not->toBeNull()
        ->and($draft->refresh()->status)->toBe(MealPlanAdjustmentDraftStatus::Superseded)
        ->and($draft->items()->sole()->mealProposal->refresh()->status)->toBe(MealProposalStatus::Replaced)
        ->and($workspace['run']->mutation_started_at)->toBeNull();
    Queue::assertPushed(ReplaceBasketJob::class, fn ($job) => $job->basketRunId === $workspace['run']->id);
});

it('marks an adjustment applied on reapproval and starts a fresh plan run', function () {
    $workspace = planRecoveryWorkspace();
    $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => validAdjustmentDrafter());
    $draft = app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run'],
        MealPlanAdjustmentKind::ProductUnavailable,
    );
    config()->set('retailer.features.experience', true);

    app(ApproveMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($draft->refresh()->status)->toBe(MealPlanAdjustmentDraftStatus::Applied)
        ->and($workspace['plan']->slots()->firstOrFail()->plannedMeal->title)->toBe('Lentil bolognese')
        ->and($workspace['plan']->basketRuns()->count())->toBe(2);
});

it('does not create an automatic recovery loop after an applied adjustment', function () {
    $workspace = planRecoveryWorkspace();
    $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => validAdjustmentDrafter());
    $draft = app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run'],
        MealPlanAdjustmentKind::ProductUnavailable,
    );
    $draft->update([
        'status' => MealPlanAdjustmentDraftStatus::Applied,
        'applied_at' => now(),
    ]);
    $workspace['plan']->increment('revision');

    expect(fn () => app(PrepareMealPlanAdjustmentDraft::class)->handle(
        $workspace['run']->refresh(),
        MealPlanAdjustmentKind::ProductUnavailable,
    ))->toThrow(ValidationException::class)
        ->and($workspace['plan']->adjustmentDrafts()->count())->toBe(1);
});

it('falls back to an actionable unchanged-basket state when adjustment output is exhausted', function () {
    $workspace = planRecoveryWorkspace();
    config()->set('retailer.features.ai_recovery', true);
    $this->app->bind(MealPlanAdjustmentDrafter::class, fn () => new class implements MealPlanAdjustmentDrafter
    {
        public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult
        {
            return new MealPlanAdjustmentDraftResult([]);
        }
    });

    (new PrepareMealPlanAdjustmentDraftJob(
        $workspace['run']->id,
        MealPlanAdjustmentKind::ProductUnavailable,
    ))->handle(app(PrepareMealPlanAdjustmentDraft::class));

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsProduct)
        ->and($workspace['run']->failure_code)->toBe('no_valid_candidate')
        ->and($workspace['run']->mutation_started_at)->toBeNull()
        ->and($workspace['run']->snapshots()->count())->toBe(0)
        ->and($workspace['plan']->adjustmentDrafts()->count())->toBe(0);
});
