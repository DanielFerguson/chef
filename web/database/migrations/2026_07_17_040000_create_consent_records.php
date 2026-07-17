<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('status');
            $table->string('purpose');
            $table->json('scope')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['team_id', 'user_id', 'kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
