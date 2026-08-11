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
        Schema::table('meal_slots', function (Blueprint $table) {
            $table->string('participant_assignment_origin')
                ->default('explicit')
                ->after('notes');
            $table->foreignId('participant_source_meal_slot_id')
                ->nullable()
                ->after('participant_assignment_origin')
                ->constrained('meal_slots')
                ->nullOnDelete();
            $table->timestamp('participant_defaults_applied_at')
                ->nullable()
                ->after('participant_source_meal_slot_id');

            $table->index(
                ['meal_plan_id', 'participant_assignment_origin'],
                'meal_slots_participant_origin_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_slots', function (Blueprint $table) {
            $table->dropIndex('meal_slots_participant_origin_index');
            $table->dropConstrainedForeignId('participant_source_meal_slot_id');
            $table->dropColumn([
                'participant_assignment_origin',
                'participant_defaults_applied_at',
            ]);
        });
    }
};
