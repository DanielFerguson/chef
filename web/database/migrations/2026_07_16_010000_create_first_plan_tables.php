<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('planning_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'starts_on', 'ends_on']);
        });

        Schema::create('meal_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind');
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'date', 'kind', 'position']);
            $table->index(['team_id', 'date']);
        });

        Schema::create('meal_slot_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->decimal('servings', 4, 2)->default(1);
            $table->timestamps();

            $table->unique(['meal_slot_id', 'person_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->timestamps();

            $table->index(['team_id', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->uuid('client_message_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'client_message_id']);
            $table->index(['team_id', 'conversation_id', 'created_at']);
        });

        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('sentiment');
            $table->unsignedTinyInteger('strength')->default(3);
            $table->string('provenance');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'person_id', 'subject']);
        });

        Schema::create('constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->string('subject');
            $table->text('details')->nullable();
            $table->string('severity')->nullable();
            $table->timestamp('explicitly_confirmed_at');
            $table->timestamps();

            $table->index(['team_id', 'person_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('constraints');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('meal_slot_participants');
        Schema::dropIfExists('meal_slots');
        Schema::dropIfExists('meal_plans');
    }
};
