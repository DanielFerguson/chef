<?php

namespace App\Actions\Baskets;

use App\Enums\BasketRunStatus;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Events\MealPlanRecipesReady;
use App\Jobs\ProbeRetailerConnectionJob;
use App\Models\BasketRun;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartBasketRunForApprovedPlan
{
    public function handle(MealPlan $mealPlan, User $user): ?BasketRun
    {
        if (! config('retailer.features.experience', false)) {
            return null;
        }

        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot prepare a basket for this meal plan.');
        }

        [$run, $recipesReady] = DB::transaction(function () use ($mealPlan, $user): array {
            $locked = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            if ($locked->planning_confirmed_at === null || $locked->derived_data_stale_at !== null) {
                throw ValidationException::withMessages([
                    'meal_plan' => 'Approve the current meal plan before preparing its basket.',
                ]);
            }

            $inputFingerprint = $this->inputFingerprint($locked);
            $existing = $locked->basketRuns()
                ->where('input_fingerprint', $inputFingerprint)
                ->first();

            if ($existing !== null) {
                return [$existing, $this->recipesReady($locked)];
            }

            $connection = RetailerConnection::query()
                ->where('team_id', $locked->team_id)
                ->where('provider', RetailerProvider::Coles)
                ->where('status', RetailerConnectionStatus::Connected)
                ->whereHas('grants', fn ($query) => $query
                    ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                    ->whereNull('revoked_at'))
                ->first();
            $recipesReady = $this->recipesReady($locked);

            $run = BasketRun::query()->create([
                'team_id' => $locked->team_id,
                'meal_plan_id' => $locked->id,
                'retailer_connection_id' => $connection?->id,
                'requested_by_user_id' => $user->id,
                'status' => $connection === null
                    ? BasketRunStatus::WaitingForConnection
                    : BasketRunStatus::WaitingForRecipes,
                'idempotency_key' => Str::uuid(),
                'input_fingerprint' => $inputFingerprint,
            ]);

            return [$run, $recipesReady];
        });

        if ($recipesReady) {
            MealPlanRecipesReady::dispatch($mealPlan->id);
        }

        if ($run->retailer_connection_id !== null) {
            ProbeRetailerConnectionJob::dispatch($run->retailer_connection_id, $run->id);
        }

        return $run->refresh();
    }

    private function recipesReady(MealPlan $mealPlan): bool
    {
        return $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->doesntExist();
    }

    private function inputFingerprint(MealPlan $mealPlan): string
    {
        $approval = $mealPlan->milestones()
            ->where('kind', MealPlanMilestoneKind::PlanningConfirmed)
            ->firstOrFail();
        $meals = $mealPlan->plannedMeals()
            ->orderBy('id')
            ->get(['id', 'servings'])
            ->map(fn ($meal): array => [
                'id' => $meal->id,
                'servings' => $meal->servings,
            ])
            ->all();

        return hash('sha256', json_encode([
            'meal_plan_id' => $mealPlan->id,
            'approval' => [
                'plan_revision' => $approval->plan_revision,
                'achieved_at' => $approval->achieved_at->toISOString(),
            ],
            'meals' => $meals,
            'provider' => RetailerProvider::Coles->value,
        ], JSON_THROW_ON_ERROR));
    }
}
