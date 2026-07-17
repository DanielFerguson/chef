<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('status');
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->json('allowed_origins');
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('shopping_list_revision');
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('browser_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('execution_surface')->default('chrome_extension');
            $table->json('scope_snapshot');
            $table->string('previous_response_id')->nullable();
            $table->string('current_tab_id')->nullable();
            $table->text('current_url')->nullable();
            $table->json('progress')->nullable();
            $table->string('pause_reason')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('takeover_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['shopping_list_id', 'shopping_list_revision']);
        });

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('response_id')->nullable();
            $table->string('call_id')->nullable();
            $table->string('status');
            $table->json('actions')->nullable();
            $table->json('safety_checks')->nullable();
            $table->json('result')->nullable();
            $table->text('current_url')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamp('screenshot_expires_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'sequence']);
            $table->unique(['automation_run_id', 'call_id']);
            $table->index(['automation_run_id', 'status']);
        });

        Schema::create('automation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_step_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('risk_kind');
            $table->string('proposed_action');
            $table->text('consequence');
            $table->string('status');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'status']);
        });

        Schema::create('automation_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('intended_name');
            $table->string('retailer_product_identifier')->nullable();
            $table->string('product_name')->nullable();
            $table->string('brand')->nullable();
            $table->string('pack')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->text('substitution_reason')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'shopping_list_item_id']);
            $table->index(['automation_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_reconciliations');
        Schema::dropIfExists('automation_approvals');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('browser_connections');
    }
};
