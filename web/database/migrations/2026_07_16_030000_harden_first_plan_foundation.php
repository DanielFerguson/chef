<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('team_id')->constrained('users')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->after('created_by_user_id')->constrained('messages')->nullOnDelete();

            $table->unique(['team_id', 'source_message_id', 'name'], 'people_source_message_name_unique');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('team_id')->constrained('people')->nullOnDelete();
        });

        Schema::table('constraints', function (Blueprint $table) {
            $table->foreignId('confirmation_message_id')->nullable()->after('created_by_user_id')->constrained('messages')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique()->after('confirmation_message_id');
        });

        Schema::table('meal_slots', function (Blueprint $table) {
            $table->foreignId('source_message_id')->nullable()->after('meal_plan_id')->constrained('messages')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique()->after('source_message_id');
        });

        Schema::table('preferences', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('evidence');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('in_reply_to_message_id')->nullable()->after('user_id')->constrained('messages')->nullOnDelete();
            $table->string('response_status')->nullable()->after('client_message_id');
            $table->text('response_error')->nullable()->after('response_status');
            $table->timestamp('response_started_at')->nullable()->after('response_error');
            $table->timestamp('response_completed_at')->nullable()->after('response_started_at');

            $table->unique('in_reply_to_message_id');
        });

        Schema::table('meal_proposals', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('meal_proposals', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['in_reply_to_message_id']);
            $table->dropConstrainedForeignId('in_reply_to_message_id');
            $table->dropColumn(['response_status', 'response_error', 'response_started_at', 'response_completed_at']);
        });

        Schema::table('meal_slots', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
            $table->dropConstrainedForeignId('source_message_id');
        });

        Schema::table('preferences', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('constraints', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
            $table->dropConstrainedForeignId('confirmation_message_id');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique('people_source_message_name_unique');
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
