<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->foreignId('source_message_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('messages')
                ->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->after('source_message_id')->unique();
            $table->index(['shopping_list_id', 'source_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropIndex(['shopping_list_id', 'source_message_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn('idempotency_key');
        });
    }
};
