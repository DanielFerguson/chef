<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->string('name', 80);
            $table->string('status', 24);
            $table->string('provider', 40)->nullable();
            $table->string('model', 100)->nullable();
            $table->uuid('invocation_id')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_microusd')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['team_id', 'category', 'occurred_at']);
            $table->index(['team_id', 'name', 'occurred_at']);
            $table->index('invocation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_events');
    }
};
