<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->foreignId('shopping_approved_by_user_id')->nullable()->after('planning_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('shopping_approved_at')->nullable()->after('shopping_approved_by_user_id');
            $table->char('shopping_approval_fingerprint', 64)->nullable()->after('shopping_approved_at');
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->string('fulfilment_method')->nullable()->after('status');
            $table->timestamp('fulfilment_scheduled_for')->nullable()->after('fulfilment_method');
            $table->timestamp('fulfilment_confirmed_at')->nullable()->after('fulfilment_scheduled_for');
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('checked');
            $table->foreignId('ordered_via_cart_snapshot_id')->nullable()->after('ordered_at')->constrained('cart_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ordered_via_cart_snapshot_id');
            $table->dropColumn('ordered_at');
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn(['fulfilment_method', 'fulfilment_scheduled_for', 'fulfilment_confirmed_at']);
        });

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_approved_by_user_id');
            $table->dropColumn(['shopping_approved_at', 'shopping_approval_fingerprint']);
        });
    }
};
