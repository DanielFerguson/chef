<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->timestamp('safety_reviewed_at')->nullable()->after('confirmed_safety_context_hash');
            $table->foreignId('safety_reviewed_by_user_id')->nullable()->after('safety_reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('safety_reviewed_context_hash', 64)->nullable()->after('safety_reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('safety_reviewed_by_user_id');
            $table->dropColumn(['safety_reviewed_at', 'safety_reviewed_context_hash']);
        });
    }
};
