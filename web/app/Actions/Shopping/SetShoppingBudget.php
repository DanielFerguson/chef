<?php

namespace App\Actions\Shopping;

use App\Models\Budget;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetShoppingBudget
{
    public function handle(MealPlan $mealPlan, User $user, float $amount, bool $householdDefault = false): Budget
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot set a budget for this plan.');
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The shopping budget must be greater than zero.']);
        }

        return DB::transaction(function () use ($mealPlan, $user, $amount, $householdDefault): Budget {
            $scopeKey = $householdDefault ? 'household' : 'plan:'.$mealPlan->id;
            $values = [
                'team_id' => $mealPlan->team_id,
                'meal_plan_id' => $householdDefault ? null : $mealPlan->id,
                'scope_key' => $scopeKey,
                'set_by_user_id' => $user->id,
                'amount' => round($amount, 2),
                'currency' => 'AUD',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            Budget::query()->upsert(
                [$values],
                ['team_id', 'scope_key'],
                ['meal_plan_id', 'set_by_user_id', 'amount', 'currency', 'updated_at'],
            );

            return Budget::query()
                ->where('team_id', $mealPlan->team_id)
                ->where('scope_key', $scopeKey)
                ->sole();
        });
    }
}
