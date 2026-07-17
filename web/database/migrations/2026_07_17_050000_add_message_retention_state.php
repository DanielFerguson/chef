<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('content_redacted_at')->nullable()->after('content');
            $table->index(['content_redacted_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['content_redacted_at', 'created_at']);
            $table->dropColumn('content_redacted_at');
        });
    }
};
