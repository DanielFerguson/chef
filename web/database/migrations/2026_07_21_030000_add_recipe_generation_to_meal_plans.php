<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->foreignId('recipe_generation_requested_by_user_id')->nullable()->after('safety_reviewed_context_hash')->constrained('users')->nullOnDelete();
            $table->string('recipe_generation_status')->nullable()->after('recipe_generation_requested_by_user_id');
            $table->string('recipe_generation_input_fingerprint', 64)->nullable()->after('recipe_generation_status');
            $table->json('recipe_generation_input')->nullable()->after('recipe_generation_input_fingerprint');
            $table->unsignedSmallInteger('recipe_generation_attempts')->default(0)->after('recipe_generation_input');
            $table->string('recipe_generation_failure_code')->nullable()->after('recipe_generation_attempts');
            $table->string('recipe_generation_failure_message')->nullable()->after('recipe_generation_failure_code');
            $table->timestamp('recipe_generation_started_at')->nullable()->after('recipe_generation_failure_message');
            $table->timestamp('recipe_generation_completed_at')->nullable()->after('recipe_generation_started_at');
            $table->index(['team_id', 'recipe_generation_status']);
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'recipe_generation_status']);
            $table->dropConstrainedForeignId('recipe_generation_requested_by_user_id');
            $table->dropColumn([
                'recipe_generation_status',
                'recipe_generation_input_fingerprint',
                'recipe_generation_input',
                'recipe_generation_attempts',
                'recipe_generation_failure_code',
                'recipe_generation_failure_message',
                'recipe_generation_started_at',
                'recipe_generation_completed_at',
            ]);
        });
    }
};
