<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('has no obsolete schema, routes, commands, or configuration', function () {
    $obsoleteTables = [
        'shopping_lists',
        'shopping_list_items',
        'retailers',
        'retail_products',
        'product_matches',
        'cart_product_plans',
        'browser_sessions',
        'retailer_order_runs',
        'orders',
        'order_lines',
    ];

    foreach ($obsoleteTables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasTable('retailer_connections'))->toBeTrue()
        ->and(Schema::hasColumn('retailer_connections', 'browserbase_context_id'))->toBeTrue()
        ->and(Schema::hasColumn('retailer_connections', 'retailer_id'))->toBeFalse()
        ->and(Schema::hasColumn('retailer_connections', 'provider_context_id'))->toBeFalse()
        ->and(Schema::hasColumn('meal_plans', 'shopping_approved_by_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('meal_plans', 'shopping_approved_at'))->toBeFalse()
        ->and(Schema::hasColumn('meal_plans', 'shopping_approval_fingerprint'))->toBeFalse();

    $routeUris = collect(Route::getRoutes()->getRoutes())->map->uri()->join("\n");
    expect($routeUris)
        ->not->toContain('shopping')
        ->not->toContain('retailer-order')
        ->not->toContain('cart-product')
        ->not->toContain('browser-session')
        ->not->toContain('order-snapshot')
        ->not->toContain('fulfilment');

    $commands = collect(Artisan::all())->keys()->join("\n");
    expect($commands)
        ->not->toContain('shopping')
        ->not->toContain('automation')
        ->and(config('services.browserbase'))->toBeNull()
        ->and(config('services.woolworths'))->toBeNull()
        ->and(config('automation'))->toBeNull()
        ->and(config('retailer.protocol'))->toBe('chef.retailer.v1')
        ->and(config('retailer.features.experience'))->toBeFalse()
        ->and(config('retailer.features.discovery'))->toBeFalse()
        ->and(config('retailer.features.mutation'))->toBeFalse()
        ->and(config('retailer.features.mutation_circuit_breaker'))->toBeTrue();
});

it('rolls the cleanup schema back empty and removes obsolete queue payloads when reapplied', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Migration family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today(), 'Migration plan');
    $cleanupMigration = require database_path('migrations/2026_07_23_140000_remove_shopping_and_retailer_stack.php');
    $basketMigration = require database_path('migrations/2026_07_31_153454_create_plan_to_basket_beta_tables.php');
    $participantProvenanceMigration = require database_path('migrations/2026_07_31_201435_add_participant_default_provenance_to_meal_slots_table.php');
    $purchasePolicyMigration = require database_path('migrations/2026_07_31_202803_add_frictionless_purchase_policy_and_search_tables.php');
    $adjustmentRecoveryMigration = require database_path('migrations/2026_07_31_205914_add_plan_adjustment_recovery_to_retailer_stack.php');
    $statusTransitionMigration = require database_path('migrations/2026_07_31_210920_create_basket_run_status_transitions_table.php');

    $statusTransitionMigration->down();
    $adjustmentRecoveryMigration->down();
    $purchasePolicyMigration->down();
    $participantProvenanceMigration->down();
    $basketMigration->down();
    $cleanupMigration->down();

    expect(Schema::hasTable('shopping_lists'))->toBeTrue()
        ->and(Schema::hasTable('retailer_connections'))->toBeTrue()
        ->and(Schema::hasTable('browser_sessions'))->toBeTrue()
        ->and(Schema::hasColumn('retailer_connections', 'retailer_id'))->toBeTrue()
        ->and(Schema::hasColumn('meal_plans', 'shopping_approved_at'))->toBeTrue();

    DB::table('meal_plan_milestones')->insert([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'user_id' => $user->id,
        'kind' => 'shopping_list_generated',
        'plan_revision' => 0,
        'achieved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('jobs')->insert([
        'queue' => 'ai',
        'payload' => '{"displayName":"App\\\\Jobs\\\\FinishPreparingShoppingListJob"}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'automation',
        'payload' => '{"displayName":"App\\\\Jobs\\\\AdvanceRetailerOrderRunJob"}',
        'exception' => 'removed job',
        'failed_at' => now(),
    ]);

    $cleanupMigration->up();

    expect(Schema::hasTable('shopping_lists'))->toBeFalse()
        ->and(Schema::hasTable('retailer_connections'))->toBeFalse()
        ->and(Schema::hasTable('browser_sessions'))->toBeFalse()
        ->and(Schema::hasColumn('meal_plans', 'shopping_approved_at'))->toBeFalse()
        ->and(DB::table('meal_plan_milestones')->where('kind', 'shopping_list_generated')->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $basketMigration->up();
    $participantProvenanceMigration->up();
    $purchasePolicyMigration->up();
    $adjustmentRecoveryMigration->up();
    $statusTransitionMigration->up();

    expect(Schema::hasTable('retailer_connections'))->toBeTrue()
        ->and(Schema::hasColumn('retailer_connections', 'browserbase_context_id'))->toBeTrue()
        ->and(Schema::hasColumn('retailer_connections', 'retailer_id'))->toBeFalse();
});
