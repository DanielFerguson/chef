<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_actors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('browser_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->uuid('actor_uuid')->unique();
            $table->text('fencing_token');
            $table->string('socket_path');
            $table->unsignedBigInteger('process_id')->nullable();
            $table->string('status')->default('starting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();

            $table->unique(['browser_session_id', 'generation']);
            $table->index(['browser_session_id', 'status']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_actors');
    }
};
