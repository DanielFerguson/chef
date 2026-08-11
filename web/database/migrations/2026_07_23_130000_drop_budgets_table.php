<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('budgets');
    }

    public function down(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key');
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('AUD');
            $table->timestamps();

            $table->unique('meal_plan_id');
            $table->unique(['team_id', 'scope_key']);
            $table->index(['team_id', 'meal_plan_id']);
        });
    }
};
