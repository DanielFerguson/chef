<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->nullable();
            $table->unsignedSmallInteger('current_step_position')->default(1);
            $table->string('replacement_title')->nullable();
            $table->date('postponed_until')->nullable();
            $table->decimal('leftover_servings', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('planned_meal_id');
            $table->index(['team_id', 'status']);
        });

        Schema::create('meal_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_outcome_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rating');
            $table->string('portion')->nullable();
            $table->string('effort')->nullable();
            $table->string('cost')->nullable();
            $table->string('leftovers')->nullable();
            $table->text('notes')->nullable();
            $table->text('recipe_adjustment')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['meal_outcome_id', 'person_id']);
            $table->index(['team_id', 'person_id', 'rating']);
        });

        Schema::create('preference_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('preference_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identity_key', 64)->unique();
            $table->string('subject');
            $table->string('normalized_subject');
            $table->string('sentiment');
            $table->unsignedSmallInteger('evidence_count');
            $table->decimal('confidence', 4, 3);
            $table->json('evidence');
            $table->string('status')->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['person_id', 'normalized_subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_candidates');
        Schema::dropIfExists('meal_feedback');
        Schema::dropIfExists('meal_outcomes');
    }
};
