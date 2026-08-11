<?php

use App\Http\Controllers\Api\V1\MealPlanController;
use App\Http\Controllers\Api\V1\MealPlanMutationController;
use App\Http\Controllers\Api\V1\MobileRegistrationController;
use App\Http\Controllers\Api\V1\MobileSessionController;
use App\Http\Controllers\Api\V1\MobileTokenController;
use App\Http\Controllers\BasketReviewSessionController;
use App\Http\Controllers\BasketRunBudgetOverrideController;
use App\Http\Controllers\BasketRunController;
use App\Http\Controllers\BasketRunItemProductPreferenceController;
use App\Http\Controllers\BasketRunRestorationController;
use App\Http\Controllers\BasketRunRetryController;
use App\Http\Controllers\ConversationMessageStreamController;
use App\Http\Controllers\MealPlanPurchasePreferenceController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\NotificationReadController;
use App\Http\Controllers\RetailerConnectionController;
use App\Http\Controllers\RetailerConnectionVerificationController;
use App\Http\Controllers\RetailerLiveInputController;
use App\Http\Controllers\RetailerLiveSessionController;
use App\Http\Controllers\RetailerProductPreferenceController;
use App\Http\Controllers\RetailerPurchasePolicyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('register', MobileRegistrationController::class)->middleware('throttle:6,1');
    Route::post('auth/tokens', [MobileTokenController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'current-team'])->group(function (): void {
        Route::delete('auth/tokens/current', [MobileTokenController::class, 'destroy']);
        Route::get('session', MobileSessionController::class);

        Route::post('meal-plans', [MealPlanController::class, 'store']);
        Route::get('meal-plans/{mealPlan}/workspace', [MealPlanController::class, 'show']);
        Route::post('meal-plans/{mealPlan}/approve', [MealPlanMutationController::class, 'approve']);
        Route::put('meal-plans/{mealPlan}/purchase-preference', MealPlanPurchasePreferenceController::class);
        Route::post('meal-plans/{mealPlan}/safety-review', [MealPlanMutationController::class, 'reviewSafety']);
        Route::put('meal-proposals/{mealProposal}/accept', [MealPlanMutationController::class, 'acceptProposal']);
        Route::put('meal-proposals/{mealProposal}/reject', [MealPlanMutationController::class, 'rejectProposal']);
        Route::put('meal-slots/{mealSlot}/participants', [MealPlanMutationController::class, 'updateParticipants']);

        Route::post('conversations/{conversation}/messages/stream', ConversationMessageStreamController::class);
        Route::get('message-attachments/{messageAttachment}', MessageAttachmentController::class);

        Route::post('retailer-connections', [RetailerConnectionController::class, 'store']);
        Route::post('retailer-connections/{retailerConnection}/verify', RetailerConnectionVerificationController::class);
        Route::post('retailer-connections/{retailerConnection}/live-input', RetailerLiveInputController::class)
            ->middleware('throttle:120,1');
        Route::delete('retailer-connections/{retailerConnection}/live-session', RetailerLiveSessionController::class);
        Route::delete('retailer-connections/{retailerConnection}/grant', [RetailerConnectionController::class, 'revokeGrant']);
        Route::delete('retailer-connections/{retailerConnection}', [RetailerConnectionController::class, 'destroy']);
        Route::get('basket-runs/{basketRun}', [BasketRunController::class, 'show']);
        Route::post('basket-runs/{basketRun}/retry', BasketRunRetryController::class);
        Route::post('basket-runs/{basketRun}/restore', BasketRunRestorationController::class);
        Route::post('basket-runs/{basketRun}/review-session', BasketReviewSessionController::class);
        Route::post('basket-runs/{basketRun}/budget-override', BasketRunBudgetOverrideController::class);
        Route::put('basket-runs/{basketRun}/items/{basketRunItem}/product-preference', BasketRunItemProductPreferenceController::class);
        Route::delete('retailer-product-preferences/{preference}', RetailerProductPreferenceController::class);
        Route::get('retailer-purchase-policy', [RetailerPurchasePolicyController::class, 'show']);
        Route::put('retailer-purchase-policy', [RetailerPurchasePolicyController::class, 'update']);
        Route::put('notifications/{notification}/read', NotificationReadController::class);
    });
});
