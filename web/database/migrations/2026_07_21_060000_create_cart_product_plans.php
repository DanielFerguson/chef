<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_product_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('discovering');
            $table->string('input_checksum', 64);
            $table->string('safety_fingerprint', 64);
            $table->json('snapshot')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->index(['shopping_list_revision_id', 'retailer_id', 'status'], 'cart_product_plans_revision_status_index');
            $table->index(['team_id', 'status']);
        });

        Schema::create('cart_product_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_product_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('status')->default('unresolved');
            $table->json('requirement_snapshot');
            $table->json('candidates')->nullable();
            $table->json('selected_product')->nullable();
            $table->string('decision_reason')->nullable();
            $table->timestamps();

            $table->unique(['cart_product_plan_id', 'position']);
            $table->index(['cart_product_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_product_plan_items');
        Schema::dropIfExists('cart_product_plans');
    }
};
