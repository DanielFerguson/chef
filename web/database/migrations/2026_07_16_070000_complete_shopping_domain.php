<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->json('stale_diff')->nullable()->after('stale_reason');
        });

        Schema::create('retailers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('retail_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('pack_quantity', 12, 3)->nullable();
            $table->string('pack_unit')->nullable();
            $table->decimal('current_price', 10, 2)->nullable();
            $table->char('currency', 3)->default('AUD');
            $table->string('product_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['retailer_id', 'external_id']);
            $table->index(['retailer_id', 'name']);
        });

        Schema::create('product_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('normalized_item_name')->nullable();
            $table->string('preferred_brand')->nullable();
            $table->string('preferred_pack')->nullable();
            $table->boolean('accept_substitutes')->default(true);
            $table->decimal('maximum_price', 10, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'normalized_item_name']);
        });

        Schema::create('product_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retail_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_preference_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('pack_count')->default(1);
            $table->decimal('estimated_total', 10, 2);
            $table->string('status')->default('selected');
            $table->text('rationale')->nullable();
            $table->boolean('preferred')->default(false);
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->unique('shopping_list_item_id');
            $table->index(['team_id', 'status']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('AUD');
            $table->timestamps();

            $table->unique('meal_plan_id');
            $table->index(['team_id', 'meal_plan_id']);
        });

        Schema::create('shopping_list_meal_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->unique(['shopping_list_id', 'planned_meal_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('shopping_list_revision');
            $table->string('status')->default('recorded');
            $table->char('currency', 3)->default('AUD');
            $table->decimal('estimated_total', 10, 2)->nullable();
            $table->decimal('actual_total', 10, 2);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['team_id', 'recorded_at']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retail_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('retailer_product_identifier')->nullable();
            $table->string('product_name');
            $table->string('brand')->nullable();
            $table->string('pack')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('substituted_from_name')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'shopping_list_item_id']);
        });

        $now = now();
        DB::table('retailers')->insert([
            ['name' => 'Coles', 'slug' => 'coles', 'website_url' => 'https://www.coles.com.au', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Woolworths', 'slug' => 'woolworths', 'website_url' => 'https://www.woolworths.com.au', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('shopping_list_meal_resolutions');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('product_matches');
        Schema::dropIfExists('product_preferences');
        Schema::dropIfExists('retail_products');
        Schema::dropIfExists('retailers');

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('stale_diff');
        });
    }
};
