<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('ai_conversation_id', 36)->nullable()->unique();
            $table->unsignedBigInteger('ai_context_cutoff_message_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['ai_conversation_id']);
            $table->dropIndex(['ai_context_cutoff_message_id']);
            $table->dropColumn(['ai_conversation_id', 'ai_context_cutoff_message_id']);
        });
    }
};
