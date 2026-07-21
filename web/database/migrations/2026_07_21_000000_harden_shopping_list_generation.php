<?php

use App\Enums\ShoppingListGenerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->string('confirmed_safety_context_hash', 64)->nullable()->after('planning_confirmed_at');
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->string('generation_status')->default(ShoppingListGenerationStatus::Pending->value)->after('status');
            $table->uuid('generation_token')->nullable()->after('generation_status');
            $table->unsignedSmallInteger('generation_attempts')->default(0)->after('generation_token');
            $table->string('generation_context_hash', 64)->nullable()->after('generation_attempts');
            $table->string('last_generation_method')->nullable()->after('generation_context_hash');
            $table->string('generation_failure_code')->nullable()->after('last_generation_method');
            $table->string('generation_failure_message')->nullable()->after('generation_failure_code');
            $table->timestamp('generation_started_at')->nullable()->after('generation_failure_message');
            $table->timestamp('generation_completed_at')->nullable()->after('generation_started_at');
            $table->index(['team_id', 'generation_status']);
        });

        DB::table('shopping_lists')
            ->where('revision', '>', 0)
            ->update([
                'generation_status' => ShoppingListGenerationStatus::Ready->value,
                'generation_completed_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'generation_status']);
            $table->dropColumn([
                'generation_status',
                'generation_token',
                'generation_attempts',
                'generation_context_hash',
                'last_generation_method',
                'generation_failure_code',
                'generation_failure_message',
                'generation_started_at',
                'generation_completed_at',
            ]);
        });

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn('confirmed_safety_context_hash');
        });
    }
};
