<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider')->default('browserbase');
            $table->text('provider_context_id')->nullable();
            $table->string('status')->default('pending_login');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('lease_owner')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'retailer_id', 'owner_user_id', 'provider'], 'retailer_connections_owner_unique');
            $table->index(['team_id', 'status']);
        });

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

        Schema::create('browser_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('provider_session_id');
            $table->string('purpose');
            $table->string('status')->default('creating');
            $table->boolean('recording_enabled')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['retailer_connection_id', 'status']);
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

        Schema::create('cart_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->char('currency', 3)->default('AUD');
            $table->decimal('chef_subtotal', 10, 2)->nullable();
            $table->decimal('cart_total', 10, 2)->nullable();
            $table->string('checksum', 64);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['automation_run_id', 'version']);
        });

        Schema::create('cart_snapshot_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('classification');
            $table->string('external_product_id')->nullable();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->boolean('pre_existing')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cart_snapshot_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_snapshot_lines');
        Schema::dropIfExists('cart_snapshots');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_interventions');
        Schema::dropIfExists('browser_sessions');
        Schema::dropIfExists('automation_run_items');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('retailer_connections');
    }
};
