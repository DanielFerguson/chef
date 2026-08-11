<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('retailer_purchase_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('home_brand_preference')->default('allow');
            $table->string('bulk_preference')->default('avoid');
            $table->string('organic_preference')->default('no_preference');
            $table->json('preferred_brands');
            $table->unsignedInteger('default_basket_target_cents')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'provider']);
        });

        Schema::create('meal_plan_purchase_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('provider');
            $table->unsignedInteger('basket_target_cents')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'provider']);
            $table->index(['team_id', 'provider']);
        });

        Schema::table('grocery_plans', function (Blueprint $table) {
            $table->json('purchase_policy_snapshot')->nullable()->after('serving_policy');
            $table->char('purchase_policy_fingerprint', 64)->nullable()->after('purchase_policy_snapshot');
            $table->unsignedInteger('effective_basket_target_cents')->nullable()->after('purchase_policy_fingerprint');
        });

        Schema::table('retailer_product_candidates', function (Blueprint $table) {
            $table->boolean('is_home_brand')->nullable()->after('brand');
            $table->boolean('is_organic')->nullable()->after('is_home_brand');
            $table->json('attribute_evidence')->nullable()->after('is_organic');
        });

        Schema::table('retailer_product_selections', function (Blueprint $table) {
            $table->unsignedSmallInteger('semantic_tier')->nullable()->after('low_confidence');
            $table->json('policy_decisions')->nullable()->after('reasoning');
            $table->json('material_exceptions')->nullable()->after('policy_decisions');
        });

        Schema::table('retailer_product_preferences', function (Blueprint $table) {
            $table->foreignId('source_message_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('messages')
                ->nullOnDelete();
        });

        Schema::create('grocery_requirement_search_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grocery_requirement_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('method');
            $table->string('query', 80);
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('eligible_result_count')->default(0);
            $table->string('reason_code')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['grocery_requirement_id', 'sequence']);
            $table->index(['team_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grocery_requirement_search_attempts');

        Schema::table('retailer_product_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_message_id');
        });

        Schema::table('retailer_product_selections', function (Blueprint $table) {
            $table->dropColumn(['semantic_tier', 'policy_decisions', 'material_exceptions']);
        });

        Schema::table('retailer_product_candidates', function (Blueprint $table) {
            $table->dropColumn(['is_home_brand', 'is_organic', 'attribute_evidence']);
        });

        Schema::table('grocery_plans', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_policy_snapshot',
                'purchase_policy_fingerprint',
                'effective_basket_target_cents',
            ]);
        });

        Schema::dropIfExists('meal_plan_purchase_preferences');
        Schema::dropIfExists('retailer_purchase_policies');
    }
};
