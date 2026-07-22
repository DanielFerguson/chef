<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_order_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_order_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('status')->default('pending');
            $table->json('requirement_snapshot');
            $table->json('matched_product')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['retailer_order_run_id', 'position']);
            $table->index(['retailer_order_run_id', 'status']);
        });

        Schema::create('retailer_order_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_order_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_order_run_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('browser_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action_type');
            $table->string('policy_decision');
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['retailer_order_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retailer_order_steps');
        Schema::dropIfExists('retailer_order_run_items');
    }
};
