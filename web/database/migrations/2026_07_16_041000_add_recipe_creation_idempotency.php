<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('created_by_user_id');
            $table->unique(['team_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
