<?php

namespace App\Actions\Automation;

use App\Actions\MealPlans\MealPlanShoppingApprovalContext;
use App\Actions\Retailer\StartRetailerOrderRun;
use App\Enums\CartProductPlanStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\ShoppingListGenerationStatus;
use App\Models\CartProductPlan;
use App\Models\MealPlan;
use App\Models\Retailer;
use App\Models\User;

class ContinueApprovedShopping
{
    public function __construct(
        private readonly BuildCartProductPlan $buildProductPlan,
        private readonly StartRetailerOrderRun $startRetailerOrderRun,
        private readonly MealPlanShoppingApprovalContext $approvalContext,
    ) {}

    public function handle(MealPlan $mealPlan, User $user): ?CartProductPlan
    {
        $mealPlan->loadMissing('shoppingList.revisions');
        $shoppingList = $mealPlan->shoppingList;

        if ($mealPlan->shopping_approved_at === null
            || ! is_string($mealPlan->shopping_approval_fingerprint)
            || $shoppingList === null
            || $shoppingList->generation_status !== ShoppingListGenerationStatus::Ready
            || $shoppingList->stale_at !== null) {
            return null;
        }

        $expectedFingerprint = $this->approvalContext->fingerprint($mealPlan);

        if (! hash_equals($mealPlan->shopping_approval_fingerprint, $expectedFingerprint)) {
            return null;
        }

        $retailer = Retailer::query()->where('slug', 'woolworths')->first();
        $connection = $retailer === null ? null : $mealPlan->team->retailerConnections()
            ->where('retailer_id', $retailer->id)
            ->where('owner_user_id', $user->id)
            ->where('status', RetailerConnectionStatus::Connected)
            ->first();
        $revision = $shoppingList->revisions->firstWhere('revision', $shoppingList->revision);

        if ($connection === null || $revision === null) {
            return null;
        }

        $productPlan = $this->buildProductPlan->handle($shoppingList, $revision, $connection, $user);

        if ($productPlan->status === CartProductPlanStatus::Ready
            && (bool) config('automation.cart_mutation_enabled')) {
            $this->startRetailerOrderRun->handle(
                $shoppingList,
                $revision,
                $connection,
                $user,
                $mealPlan->shopping_approval_fingerprint,
                safetyAcknowledged: true,
                productPlanReviewed: true,
            );
        }

        return $productPlan;
    }
}
