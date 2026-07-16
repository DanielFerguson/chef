<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preferences', function (Blueprint $table) {
            $table->foreignId('source_message_id')->nullable()->after('evidence')->constrained('messages')->nullOnDelete();
            $table->text('evidence_quote')->nullable()->after('source_message_id');
            $table->foreignId('correction_message_id')->nullable()->after('evidence_quote')->constrained('messages')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->after('correction_message_id')->constrained('preferences')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('superseded_by_id');

            $table->index(['team_id', 'person_id', 'superseded_at']);
        });

        DB::table('preferences')->whereNotNull('evidence')->orderBy('id')->each(function (object $preference): void {
            $evidence = json_decode((string) $preference->evidence, true);
            $messageId = is_array($evidence) ? ($evidence['message_id'] ?? null) : null;

            if (is_int($messageId) || ctype_digit((string) $messageId)) {
                DB::table('preferences')->where('id', $preference->id)->update(['source_message_id' => (int) $messageId]);
            }
        });

        Schema::create('conversation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context');
            $table->string('rating');
            $table->json('reasons')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('plan_revision')->nullable();
            $table->string('milestone')->nullable();
            $table->string('invocation_id')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->unique(['conversation_id', 'meal_plan_id', 'user_id', 'context'], 'conversation_feedback_checkpoint_unique');
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_feedback');

        Schema::table('preferences', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'person_id', 'superseded_at']);
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropConstrainedForeignId('correction_message_id');
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn(['evidence_quote', 'superseded_at']);
        });
    }
};
