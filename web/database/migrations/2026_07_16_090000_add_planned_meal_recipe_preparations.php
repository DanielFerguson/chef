<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_meal_recipe_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('input_fingerprint', 64);
            $table->json('input');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_meal_recipe_preparations');
    }
};
