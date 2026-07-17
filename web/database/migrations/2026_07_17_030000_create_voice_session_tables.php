<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('model');
            $table->string('voice');
            $table->text('last_error')->nullable();
            $table->timestamp('microphone_permission_granted_at');
            $table->timestamp('microphone_permission_revoked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['team_id', 'conversation_id', 'created_at']);
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('voice_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voice_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_call_id');
            $table->string('tool_name');
            $table->json('arguments');
            $table->uuid('client_message_id');
            $table->foreignId('user_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status');
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['voice_session_id', 'provider_call_id']);
            $table->unique(['conversation_id', 'client_message_id']);
            $table->index(['team_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_tool_calls');
        Schema::dropIfExists('voice_sessions');
    }
};
