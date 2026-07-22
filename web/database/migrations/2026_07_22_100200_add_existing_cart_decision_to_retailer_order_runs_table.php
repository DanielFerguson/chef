<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retailer_order_runs', function (Blueprint $table) {
            $table->string('existing_cart_decision')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('retailer_order_runs', function (Blueprint $table) {
            $table->dropColumn('existing_cart_decision');
        });
    }
};
