<?php

namespace App\Actions\MealPlans;

use App\Actions\Planning\AcceptMealProposal;
use App\Actions\Shopping\PrepareMealPlanShoppingList;
use App\Enums\MealProposalStatus;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ApproveMealPlanForShopping
{
    public function __construct(
        private readonly AcceptMealProposal $acceptProposal,
        private readonly ReviewMealPlanSafety $reviewSafety,
        private readonly ConfirmMealPlan $confirmPlan,
        private readonly PrepareMealPlanShoppingList $prepareShopping,
        private readonly MealPlanShoppingApprovalContext $approvalContext,
    ) {}

    public function handle(MealPlan $mealPlan, User $user, ?string $fulfilmentMethod = null): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot approve this meal plan.');
        }

        if ($fulfilmentMethod !== null && ! in_array($fulfilmentMethod, ['delivery', 'pickup'], true)) {
            throw ValidationException::withMessages([
                'fulfilment_method' => 'Choose delivery or pickup.',
            ]);
        }

        $mealPlan->load('slots.plannedMeal', 'proposals');
        $openSlots = $mealPlan->slots->filter(fn ($slot) => $slot->plannedMeal === null);

        foreach ($openSlots as $slot) {
            $slotProposals = $mealPlan->proposals
                ->where('status', MealProposalStatus::Pending)
                ->where('meal_slot_id', $slot->id);

            if ($slotProposals->count() !== 1) {
                throw ValidationException::withMessages([
                    'plan' => 'Every open meal slot needs one clear draft meal before the plan can be approved.',
                ]);
            }
        }

        $pendingForFilledSlots = $mealPlan->proposals
            ->where('status', MealProposalStatus::Pending)
            ->whereIn('meal_slot_id', $mealPlan->slots->whereNotNull('plannedMeal')->pluck('id'));

        if ($pendingForFilledSlots->isNotEmpty()) {
            throw ValidationException::withMessages([
                'plan' => 'Resolve the alternative meals on already-filled days before approving the plan.',
            ]);
        }

        foreach ($openSlots->sortBy([['date', 'asc'], ['position', 'asc']]) as $slot) {
            $proposal = $mealPlan->proposals
                ->where('status', MealProposalStatus::Pending)
                ->where('meal_slot_id', $slot->id)
                ->sole();
            $this->acceptProposal->handle($proposal, $user);
        }

        $mealPlan = $mealPlan->refresh();
        $this->reviewSafety->handle($mealPlan, $user);
        $this->confirmPlan->handle($mealPlan->refresh(), $user);

        $mealPlan = $mealPlan->refresh();
        $mealPlan->update([
            'shopping_approved_by_user_id' => $user->id,
            'shopping_approved_at' => now(),
            'shopping_approval_fingerprint' => $this->approvalContext->fingerprint($mealPlan),
        ]);

        $this->prepareShopping->handle($mealPlan->refresh(), $user);

        if ($fulfilmentMethod !== null) {
            $mealPlan->shoppingList()->update(['fulfilment_method' => $fulfilmentMethod]);
        }

        return $mealPlan->refresh()->load('shoppingList');
    }
}
