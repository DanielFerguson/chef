<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_sessions', function (Blueprint $table) {
            $table->dropIndex(['automation_run_id', 'status']);
            $table->dropConstrainedForeignId('automation_run_id');
        });

        Schema::table('cart_product_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_run_id');
        });

        Schema::table('cart_snapshot_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_run_item_id');
        });

        Schema::table('cart_snapshots', function (Blueprint $table) {
            $table->dropUnique(['automation_run_id', 'version']);
            $table->dropConstrainedForeignId('automation_run_id');
        });

        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_interventions');
        Schema::dropIfExists('automation_run_items');
        Schema::dropIfExists('automation_runs');
    }

    public function down(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('checking_connection');
            $table->string('existing_cart_decision')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('frozen_snapshot');
            $table->string('frozen_snapshot_checksum', 64);
            $table->json('limits')->nullable();
            $table->text('openai_response_id')->nullable();
            $table->unsignedInteger('current_sequence')->default(0);
            $table->unsignedInteger('actions_taken')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['retailer_connection_id', 'status']);
            $table->index(['shopping_list_id', 'shopping_list_revision_id'], 'automation_runs_revision_index');
        });

        Schema::create('automation_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('status')->default('pending');
            $table->json('requirement_snapshot');
            $table->json('matched_product')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'position']);
            $table->index(['automation_run_id', 'status']);
        });

        Schema::create('automation_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('browser_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->json('resolution')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'status']);
        });

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('browser_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action_type');
            $table->string('policy_decision');
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'sequence']);
        });

        Schema::table('cart_snapshots', function (Blueprint $table) {
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('cart_snapshot_lines', function (Blueprint $table) {
            $table->foreignId('automation_run_item_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('cart_product_plans', function (Blueprint $table) {
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('browser_sessions', function (Blueprint $table) {
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['automation_run_id', 'status']);
        });
    }
};
