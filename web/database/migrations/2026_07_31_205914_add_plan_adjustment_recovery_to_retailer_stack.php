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
        Schema::create('meal_plan_adjustment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('originating_plan_revision');
            $table->string('kind');
            $table->string('status');
            $table->char('input_fingerprint', 64);
            $table->timestamp('generated_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['meal_plan_id', 'originating_plan_revision', 'kind'],
                'meal_plan_adjustment_drafts_version_kind_unique',
            );
            $table->index(['team_id', 'status']);
        });

        Schema::create('meal_plan_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_adjustment_draft_id')
                ->constrained('meal_plan_adjustment_drafts')
                ->cascadeOnDelete();
            $table->foreignId('meal_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meal_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('replacement_title');
            $table->text('replacement_summary')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('estimated_cost_cents')->nullable();
            $table->json('covered_requirement_ids');
            $table->timestamps();

            $table->unique(
                ['meal_plan_adjustment_draft_id', 'meal_slot_id'],
                'meal_plan_adjustment_items_slot_unique',
            );
        });

        Schema::table('basket_runs', function (Blueprint $table) {
            $table->string('attention_kind')->nullable()->after('failure_message');
            $table->json('attention_details')->nullable()->after('attention_kind');
            $table->unsignedInteger('budget_override_cents')->nullable()->after('attention_details');
            $table->foreignId('budget_override_by_user_id')
                ->nullable()
                ->after('budget_override_cents')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('budget_overridden_at')->nullable()->after('budget_override_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('basket_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_override_by_user_id');
            $table->dropColumn([
                'attention_kind',
                'attention_details',
                'budget_override_cents',
                'budget_overridden_at',
            ]);
        });

        Schema::dropIfExists('meal_plan_adjustment_items');
        Schema::dropIfExists('meal_plan_adjustment_drafts');
    }
};
