<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('scope_key')->nullable()->after('meal_plan_id');
        });

        $seenBudgetScopes = [];

        foreach (DB::table('budgets')->orderByDesc('id')->get() as $budget) {
            $scopeKey = $budget->meal_plan_id === null ? 'household' : 'plan:'.$budget->meal_plan_id;
            $identity = $budget->team_id.'|'.$scopeKey;

            if (isset($seenBudgetScopes[$identity])) {
                DB::table('budgets')->where('id', $budget->id)->delete();

                continue;
            }

            $seenBudgetScopes[$identity] = true;
            DB::table('budgets')->where('id', $budget->id)->update(['scope_key' => $scopeKey]);
        }

        Schema::table('budgets', function (Blueprint $table) {
            $table->string('scope_key')->nullable(false)->change();
            $table->unique(['team_id', 'scope_key']);
        });

        Schema::table('product_preferences', function (Blueprint $table) {
            $table->string('identity_key', 64)->nullable()->after('team_id');
        });

        $seenPreferenceIdentities = [];

        foreach (DB::table('product_preferences')->orderByDesc('id')->get() as $preference) {
            $identityKey = hash('sha256', implode('|', [
                'retailer:'.($preference->retailer_id ?? 'any'),
                $preference->ingredient_id === null
                    ? 'name:'.($preference->normalized_item_name ?? 'unscoped:'.$preference->id)
                    : 'ingredient:'.$preference->ingredient_id,
            ]));
            $identity = $preference->team_id.'|'.$identityKey;

            if (isset($seenPreferenceIdentities[$identity])) {
                DB::table('product_preferences')->where('id', $preference->id)->delete();

                continue;
            }

            $seenPreferenceIdentities[$identity] = true;
            DB::table('product_preferences')->where('id', $preference->id)->update(['identity_key' => $identityKey]);
        }

        Schema::table('product_preferences', function (Blueprint $table) {
            $table->string('identity_key', 64)->nullable(false)->change();
            $table->unique(['team_id', 'identity_key']);
        });
    }

    public function down(): void
    {
        Schema::table('product_preferences', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'identity_key']);
            $table->dropColumn('identity_key');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'scope_key']);
            $table->dropColumn('scope_key');
        });
    }
};
