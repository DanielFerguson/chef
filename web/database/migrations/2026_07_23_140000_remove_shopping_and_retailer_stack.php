<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const REMOVED_JOB_CLASSES = [
        'FinishPreparingShoppingListJob',
        'AdvanceAutomationRunJob',
        'AdvanceRetailerOrderRunJob',
    ];

    public function up(): void
    {
        DB::table('meal_plan_milestones')
            ->whereIn('kind', ['shopping_list_generated', 'shopping_completed'])
            ->delete();

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where(function ($query): void {
                    foreach (self::REMOVED_JOB_CLASSES as $jobClass) {
                        $query->orWhere('payload', 'like', '%'.$jobClass.'%');
                    }
                })
                ->delete();
        }

        $tables = [
            'order_lines',
            'orders',
            'retailer_order_steps',
            'retailer_order_run_items',
            'cart_snapshot_lines',
            'retailer_order_runs',
            'browser_actors',
            'browser_sessions',
            'automation_steps',
            'automation_interventions',
            'automation_run_items',
            'automation_runs',
            'cart_product_plan_items',
            'cart_product_plans',
            'product_matches',
            'product_preferences',
            'retailer_connections',
            'retail_products',
            'retailers',
            'shopping_list_item_sources',
            'shopping_list_revisions',
            'shopping_list_items',
            'shopping_lists',
            'cart_snapshots',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_approved_by_user_id');
            $table->dropColumn(['shopping_approved_at', 'shopping_approval_fingerprint']);
        });
    }

    public function down(): void
    {
        $migrations = [
            '2026_07_16_060000_create_shopping_list_tables.php',
            '2026_07_16_070000_complete_shopping_domain.php',
            '2026_07_16_100000_add_category_to_shopping_list_items.php',
            '2026_07_17_000000_add_conversation_idempotency_to_shopping_list_items.php',
            '2026_07_20_000000_create_retailer_automation_tables.php',
        ];

        foreach ($migrations as $migration) {
            $instance = require database_path('migrations/'.$migration);
            $instance->up();
        }

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->string('generation_status')->default('pending')->after('status');
            $table->uuid('generation_token')->nullable()->after('generation_status');
            $table->unsignedSmallInteger('generation_attempts')->default(0)->after('generation_token');
            $table->string('generation_context_hash', 64)->nullable()->after('generation_attempts');
            $table->string('last_generation_method')->nullable()->after('generation_context_hash');
            $table->string('generation_failure_code')->nullable()->after('last_generation_method');
            $table->string('generation_failure_message')->nullable()->after('generation_failure_code');
            $table->timestamp('generation_started_at')->nullable()->after('generation_failure_message');
            $table->timestamp('generation_completed_at')->nullable()->after('generation_started_at');
            $table->index(['team_id', 'generation_status']);
        });

        $remainingMigrations = [
            '2026_07_21_040000_link_orders_to_cart_snapshots.php',
            '2026_07_21_050000_create_browser_actors.php',
            '2026_07_21_060000_create_cart_product_plans.php',
            '2026_07_22_000000_add_momentum_first_journey_state.php',
            '2026_07_22_100000_create_retailer_order_runs_table.php',
            '2026_07_22_100100_create_retailer_order_run_items_and_steps_tables.php',
            '2026_07_22_100200_add_existing_cart_decision_to_retailer_order_runs_table.php',
            '2026_07_22_200000_drop_automation_runs_tables.php',
            '2026_07_23_120000_drop_shopping_list_meal_resolutions_table.php',
            '2026_07_23_130000_drop_budgets_table.php',
        ];

        foreach ($remainingMigrations as $migration) {
            $instance = require database_path('migrations/'.$migration);
            $instance->up();
        }
    }
};
