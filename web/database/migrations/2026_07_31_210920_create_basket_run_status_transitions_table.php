<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('basket_run_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_run_id')->constrained()->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->string('reason_code')->nullable();
            $table->unsignedBigInteger('duration_ms');
            $table->timestamp('transitioned_at');
            $table->timestamps();

            $table->index(['team_id', 'transitioned_at']);
            $table->index(['basket_run_id', 'transitioned_at']);
        });

        Schema::table('basket_runs', function (Blueprint $table) {
            $table->unsignedInteger('stagehand_fallback_count')->default(0)->after('claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('basket_runs', function (Blueprint $table) {
            $table->dropColumn('stagehand_fallback_count');
        });

        Schema::dropIfExists('basket_run_status_transitions');
    }
};
