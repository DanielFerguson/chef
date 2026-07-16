<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->timestamps();

            $table->unique(['team_id', 'normalized_name']);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'title']);
        });

        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->decimal('servings', 5, 2)->default(2);
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['recipe_id', 'version']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit')->nullable();
            $table->string('preparation')->nullable();
            $table->boolean('optional')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['recipe_version_id', 'position']);
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('instruction');
            $table->unsignedSmallInteger('timer_minutes')->nullable();
            $table->timestamps();

            $table->unique(['recipe_version_id', 'position']);
        });

        Schema::create('recipe_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->timestamps();

            $table->unique(['recipe_version_id', 'position']);
        });

        Schema::create('recipe_preparation_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind');
            $table->text('instruction');
            $table->unsignedInteger('lead_minutes')->nullable();
            $table->timestamps();

            $table->unique(['recipe_version_id', 'position']);
        });

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('ends_on');
            $table->timestamp('derived_data_stale_at')->nullable()->after('planning_confirmed_at');
            $table->string('derived_data_stale_reason')->nullable()->after('derived_data_stale_at');
        });

        Schema::create('meal_plan_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('summary');
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'revision']);
        });

        Schema::create('meal_plan_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->unsignedInteger('plan_revision');
            $table->timestamp('achieved_at');
            $table->timestamps();

            $table->unique(['meal_plan_id', 'kind']);
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->foreignId('recipe_version_id')->nullable()->after('meal_proposal_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_planned_meal_id')->nullable()->after('recipe_version_id')->constrained('planned_meals')->nullOnDelete();
            $table->string('type')->default('custom')->after('selected_by_user_id');
            $table->string('status')->default('planned')->after('type');
            $table->decimal('servings', 5, 2)->default(2)->after('status');
            $table->text('notes')->nullable()->after('summary');
            $table->json('recommendation_explanation')->nullable()->after('estimated_cost');
        });
    }

    public function down(): void
    {
        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_planned_meal_id');
            $table->dropConstrainedForeignId('recipe_version_id');
            $table->dropColumn(['type', 'status', 'servings', 'notes', 'recommendation_explanation']);
        });

        Schema::dropIfExists('meal_plan_milestones');
        Schema::dropIfExists('meal_plan_revisions');

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn(['revision', 'derived_data_stale_at', 'derived_data_stale_reason']);
        });

        Schema::dropIfExists('recipe_preparation_notices');
        Schema::dropIfExists('recipe_equipment');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('ingredients');
    }
};
