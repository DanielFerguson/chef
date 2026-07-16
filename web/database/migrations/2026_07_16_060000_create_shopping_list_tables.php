<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('source_plan_revision');
            $table->string('status')->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->string('stale_reason')->nullable();
            $table->timestamps();

            $table->unique('meal_plan_id');
            $table->index(['team_id', 'updated_at']);
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_kind');
            $table->string('name');
            $table->string('normalized_name');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();
            $table->text('note')->nullable();
            $table->boolean('included')->default(true);
            $table->boolean('in_pantry')->default(false);
            $table->boolean('checked')->default(false);
            $table->boolean('optional')->default(false);
            $table->decimal('estimated_price', 10, 2)->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['shopping_list_id', 'position']);
            $table->index(['shopping_list_id', 'normalized_name']);
        });

        Schema::create('shopping_list_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();
            $table->timestamps();

            $table->index(['shopping_list_item_id', 'planned_meal_id']);
        });

        Schema::create('shopping_list_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('summary');
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['shopping_list_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_revisions');
        Schema::dropIfExists('shopping_list_item_sources');
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
