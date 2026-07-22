<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailer_order_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cart_product_plan_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('fulfilment_type')->nullable();
            $table->json('fulfilment_options')->nullable();
            $table->timestamp('fulfilment_options_expires_at')->nullable();
            $table->json('selected_slot')->nullable();
            $table->json('confirmation')->nullable();
            $table->string('cart_checksum')->nullable();
            $table->string('confirmation_fingerprint')->nullable();
            $table->string('retailer_order_reference')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('limits')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['retailer_connection_id', 'status']);
            $table->index(['shopping_list_id', 'shopping_list_revision_id'], 'retailer_order_runs_revision_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retailer_order_runs');
    }
};
