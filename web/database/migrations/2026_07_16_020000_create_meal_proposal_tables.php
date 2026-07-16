<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->decimal('estimated_cost', 8, 2)->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'meal_plan_id', 'status']);
        });

        Schema::create('planned_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_slot_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('meal_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->decimal('estimated_cost', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'meal_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_meals');
        Schema::dropIfExists('meal_proposals');
    }
};
