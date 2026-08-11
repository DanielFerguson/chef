<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Retailers\RuntimeRetailerMutationCircuitBreaker;
use App\Actions\Retailers\StartRetailerConnection;
use App\Actions\Retailers\VerifyRetailerConnection;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\MealSlotKind;
use App\Models\BasketRun;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Support\Facades\DB;

it('finishes the approved-plan retailer pipeline through PostgreSQL and an external Redis worker', function () {
    if (DB::connection()->getDriverName() !== 'pgsql'
        || config('queue.default') !== 'redis'
        || config('cache.default') !== 'redis') {
        $this->markTestSkipped('The retailer integration gate requires PostgreSQL, Redis cache, and a Redis queue worker.');
    }

    expect(config('retailer.testing.recorded_fixture'))->not->toBeNull();

    app(RuntimeRetailerMutationCircuitBreaker::class)->close('Pest integration gate');
    $user = User::factory()->create(['name' => 'Integration Cook']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Integration Kitchen');
    $gateway = app(RetailerAutomationGateway::class);
    $connectionResult = app(StartRetailerConnection::class)->handle($team, $user, $gateway);
    app(VerifyRetailerConnection::class)->handle($connectionResult['connection'], $user, $gateway);

    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDay(),
        'External worker integration',
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
        'A deterministic integration dinner.',
        30,
        18,
    );

    app(ApproveMealPlan::class)->handle($plan, $user);
    $run = $plan->basketRuns()->sole();
    $deadline = microtime(true) + 30;

    do {
        usleep(100_000);
        $run->refresh();
    } while (! $run->status->isTerminal() && microtime(true) < $deadline);

    $run->load('groceryPlan.requirements.selection.candidate', 'items', 'snapshots');

    expect($run->status)->toBe(BasketRunStatus::Ready)
        ->and($run->groceryPlan)->not->toBeNull()
        ->and($run->groceryPlan->requirements)->toHaveCount(1)
        ->and($run->groceryPlan->requirements->sole()->selection?->candidate?->sku)->toBe('recorded-satay-500')
        ->and($run->items)->toHaveCount(1)
        ->and($run->items->sole()->verified_at)->not->toBeNull()
        ->and($run->snapshots)->toHaveCount(2)
        ->and(BasketRun::query()->whereKey($run)->value('claim_token'))->toBeNull();
})->group('integration');
