<?php

namespace App\Actions\MealPlans;

use App\Actions\Baskets\StartBasketRunForApprovedPlan;
use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealProposalStatus;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveMealPlan
{
    public function __construct(
        private readonly AcceptMealProposal $acceptProposal,
        private readonly ConfirmMealPlan $confirmPlan,
        private readonly PrepareMealPlanRecipes $prepareRecipes,
        private readonly StartBasketRunForApprovedPlan $startBasketRun,
    ) {}

    public function handle(MealPlan $mealPlan, User $user): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot approve this meal plan.');
        }

        $mealPlan = DB::transaction(function () use ($mealPlan, $user): MealPlan {
            $locked = MealPlan::query()
                ->whereKey($mealPlan)
                ->lockForUpdate()
                ->with(['slots.plannedMeal', 'proposals'])
                ->firstOrFail();
            $pending = $locked->proposals->where('status', MealProposalStatus::Pending);

            if ($pending->whereNull('meal_slot_id')->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'plan' => 'Assign every draft meal to a meal slot before approving the plan.',
                ]);
            }

            foreach ($locked->slots as $slot) {
                $slotProposals = $pending->where('meal_slot_id', $slot->id);

                if (($slot->plannedMeal === null && $slotProposals->count() !== 1)
                    || $slotProposals->count() > 1) {
                    throw ValidationException::withMessages([
                        'plan' => 'Every meal slot needs one clear effective meal before the plan can be approved.',
                    ]);
                }
            }

            foreach ($locked->slots->sortBy([['date', 'asc'], ['position', 'asc']]) as $slot) {
                $proposal = $pending->where('meal_slot_id', $slot->id)->first();

                if ($proposal !== null) {
                    $this->acceptProposal->handle($proposal, $user);
                }
            }

            $locked->adjustmentDrafts()
                ->where('status', MealPlanAdjustmentDraftStatus::Pending)
                ->with('items.mealProposal')
                ->get()
                ->filter(fn ($draft): bool => $draft->items->isNotEmpty()
                    && $draft->items->every(
                        fn ($item): bool => $item->mealProposal?->status === MealProposalStatus::Accepted,
                    ))
                ->each(fn ($draft) => $draft->update([
                    'status' => MealPlanAdjustmentDraftStatus::Applied,
                    'applied_at' => now(),
                ]));

            $locked = $locked->refresh();
            $this->confirmPlan->handle($locked, $user);

            return $locked->refresh();
        });

        $preparedPlan = $this->prepareRecipes->handle($mealPlan->refresh(), $user);
        $this->startBasketRun->handle($preparedPlan, $user);

        return $preparedPlan;
    }
}
