<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('status');
            $table->text('browserbase_context_id')->nullable();
            $table->char('context_lookup_hash', 64)->nullable();
            $table->text('active_session_id')->nullable();
            $table->uuid('active_session_claim_token')->nullable();
            $table->string('active_session_purpose')->nullable();
            $table->timestamp('active_session_started_at')->nullable();
            $table->timestamp('active_session_expires_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'provider']);
            $table->unique('context_lookup_hash');
            $table->index(['status', 'updated_at']);
        });

        Schema::create('retailer_automation_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope');
            $table->string('disclosure_version');
            $table->char('disclosure_hash', 64);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'scope', 'revoked_at']);
            $table->index(['retailer_connection_id', 'granted_at']);
        });

        Schema::create('grocery_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->char('input_fingerprint', 64);
            $table->char('recipe_fingerprint', 64);
            $table->string('serving_policy')->default('planned_servings');
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'version']);
            $table->unique(['meal_plan_id', 'input_fingerprint']);
            $table->index(['team_id', 'status', 'updated_at']);
        });

        Schema::create('grocery_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('display_name');
            $table->string('normalized_name');
            $table->string('normalized_form')->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->string('unit')->nullable();
            $table->boolean('quantity_unknown')->default(false);
            $table->char('fingerprint', 64);
            $table->json('search_queries');
            $table->json('applicable_constraints')->nullable();
            $table->timestamps();

            $table->unique(['grocery_plan_id', 'fingerprint']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('grocery_requirement_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_ingredient_id')->constrained()->restrictOnDelete();
            $table->string('source_name');
            $table->string('preparation')->nullable();
            $table->decimal('source_quantity', 12, 4)->nullable();
            $table->decimal('scaled_quantity', 12, 4)->nullable();
            $table->decimal('serving_factor', 10, 4);
            $table->string('unit')->nullable();
            $table->timestamps();

            $table->unique(
                ['grocery_requirement_id', 'planned_meal_id', 'recipe_ingredient_id'],
                'grocery_requirement_sources_unique',
            );
        });

        Schema::create('retailer_product_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_requirement_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('sku');
            $table->string('title');
            $table->string('brand')->nullable();
            $table->string('semantic_key');
            $table->string('origin_host');
            $table->string('product_path', 2048)->nullable();
            $table->decimal('pack_quantity', 12, 4)->nullable();
            $table->string('pack_unit')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->boolean('available')->default(false);
            $table->string('status');
            $table->json('rejection_codes')->nullable();
            $table->json('label_evidence')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['grocery_requirement_id', 'provider', 'sku'],
                'retailer_product_candidates_unique',
            );
            $table->index(['team_id', 'status']);
        });

        Schema::create('retailer_product_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_requirement_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_product_candidate_id')->constrained()->restrictOnDelete();
            $table->string('method');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('low_confidence')->default(false);
            $table->text('reasoning');
            $table->unsignedInteger('pack_count');
            $table->decimal('required_quantity', 12, 4)->nullable();
            $table->decimal('total_quantity', 12, 4)->nullable();
            $table->decimal('waste_quantity', 12, 4)->nullable();
            $table->unsignedInteger('total_price_cents');
            $table->char('selection_checksum', 64);
            $table->char('revalidation_checksum', 64)->nullable();
            $table->timestamp('selected_at');
            $table->timestamp('revalidated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('retailer_product_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('normalized_name');
            $table->string('normalized_form')->nullable();
            $table->string('sku');
            $table->string('product_title');
            $table->text('reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['team_id', 'provider', 'normalized_name', 'revoked_at'],
                'retailer_preferences_lookup',
            );
        });

        Schema::create('basket_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retailer_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->uuid('idempotency_key')->unique();
            $table->char('input_fingerprint', 64);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('mutation_started_at')->nullable();
            $table->timestamp('basket_cleared_at')->nullable();
            $table->char('baseline_checksum', 64)->nullable();
            $table->char('target_checksum', 64)->nullable();
            $table->char('final_checksum', 64)->nullable();
            $table->unsignedInteger('replaced_line_count')->default(0);
            $table->unsignedInteger('chef_subtotal_cents')->nullable();
            $table->unsignedInteger('retailer_total_cents')->nullable();
            $table->timestamp('basket_captured_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('restore_requested_at')->nullable();
            $table->timestamp('restore_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'input_fingerprint']);
            $table->index(['team_id', 'status', 'updated_at']);
        });

        Schema::create('basket_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_requirement_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_product_selection_id')->constrained()->restrictOnDelete();
            $table->string('sku');
            $table->string('product_title');
            $table->unsignedInteger('absolute_quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('line_price_cents');
            $table->text('pack_reasoning');
            $table->char('verification_checksum', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['basket_run_id', 'grocery_requirement_id']);
            $table->index(['basket_run_id', 'sku']);
        });

        Schema::create('basket_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_run_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('retailer_total_cents')->nullable();
            $table->unsignedInteger('line_count');
            $table->char('checksum', 64);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['basket_run_id', 'kind']);
        });

        Schema::create('basket_snapshot_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('product_title');
            $table->unsignedInteger('absolute_quantity');
            $table->unsignedInteger('unit_price_cents')->nullable();
            $table->unsignedInteger('line_price_cents')->nullable();
            $table->char('checksum', 64);
            $table->timestamps();

            $table->unique(['basket_snapshot_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basket_snapshot_lines');
        Schema::dropIfExists('basket_snapshots');
        Schema::dropIfExists('basket_run_items');
        Schema::dropIfExists('basket_runs');
        Schema::dropIfExists('retailer_product_preferences');
        Schema::dropIfExists('retailer_product_selections');
        Schema::dropIfExists('retailer_product_candidates');
        Schema::dropIfExists('grocery_requirement_sources');
        Schema::dropIfExists('grocery_requirements');
        Schema::dropIfExists('grocery_plans');
        Schema::dropIfExists('retailer_automation_grants');
        Schema::dropIfExists('retailer_connections');
    }
};
