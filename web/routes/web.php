<?php

use App\Http\Controllers\BasketReviewSessionController;
use App\Http\Controllers\BasketRunBudgetOverrideController;
use App\Http\Controllers\BasketRunController;
use App\Http\Controllers\BasketRunItemProductPreferenceController;
use App\Http\Controllers\BasketRunRestorationController;
use App\Http\Controllers\BasketRunRetryController;
use App\Http\Controllers\ConstraintController;
use App\Http\Controllers\ConversationFeedbackController;
use App\Http\Controllers\ConversationMessageStreamController;
use App\Http\Controllers\CookingController;
use App\Http\Controllers\CookingProgressController;
use App\Http\Controllers\CookingStartController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MealFeedbackController;
use App\Http\Controllers\MealOutcomeController;
use App\Http\Controllers\MealPlanApprovalController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealPlanMilestoneController;
use App\Http\Controllers\MealPlanPurchasePreferenceController;
use App\Http\Controllers\MealPlanRecipePreparationController;
use App\Http\Controllers\MealPlanSafetyReviewController;
use App\Http\Controllers\MealProposalDecisionController;
use App\Http\Controllers\MealSlotController;
use App\Http\Controllers\MealSlotParticipantController;
use App\Http\Controllers\MealSlotPlannedMealController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\MessageFeedbackController;
use App\Http\Controllers\NotificationReadController;
use App\Http\Controllers\PlannedMealController;
use App\Http\Controllers\PlannedMealMoveController;
use App\Http\Controllers\PreferenceCandidateController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeImportController;
use App\Http\Controllers\RecipeVersionController;
use App\Http\Controllers\RetailerConnectionController;
use App\Http\Controllers\RetailerConnectionVerificationController;
use App\Http\Controllers\RetailerLiveSessionController;
use App\Http\Controllers\RetailerProductPreferenceController;
use App\Http\Controllers\SwitchTeamController;
use App\Http\Controllers\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->middleware('auth')->name('home');

Route::get('invitations/{teamInvitation}', [TeamInvitationController::class, 'show'])
    ->middleware('signed')
    ->name('team-invitations.show');

Route::middleware(['auth', 'verified', 'current-team'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('planned-meals/{plannedMeal}/cook', [CookingController::class, 'show'])->name('planned-meals.cook.show');
    Route::post('planned-meals/{plannedMeal}/cook', CookingStartController::class)->name('planned-meals.cook.start');
    Route::put('meal-outcomes/{mealOutcome}/progress', CookingProgressController::class)->name('meal-outcomes.progress.update');
    Route::put('planned-meals/{plannedMeal}/outcome', [MealOutcomeController::class, 'update'])->name('planned-meals.outcome.update');
    Route::put('meal-outcomes/{mealOutcome}/people/{person}/feedback', [MealFeedbackController::class, 'update'])->name('meal-outcomes.feedback.update');
    Route::put('preference-candidates/{preferenceCandidate}', [PreferenceCandidateController::class, 'update'])->name('preference-candidates.update');
    Route::post('meal-plans', [MealPlanController::class, 'store'])->name('meal-plans.store');
    Route::get('meal-plans/{mealPlan}', [MealPlanController::class, 'show'])->name('meal-plans.show');
    Route::put('meal-plans/{mealPlan}', [MealPlanController::class, 'update'])->name('meal-plans.update');
    Route::delete('meal-plans/{mealPlan}', [MealPlanController::class, 'destroy'])->name('meal-plans.destroy');
    Route::post('meal-plans/{mealPlan}/approve', MealPlanApprovalController::class)->name('meal-plans.approve');
    Route::put('meal-plans/{mealPlan}/purchase-preference', MealPlanPurchasePreferenceController::class)->name('meal-plans.purchase-preference.update');
    Route::post('meal-plans/{mealPlan}/recipes/prepare', MealPlanRecipePreparationController::class)->name('meal-plans.recipes.prepare');
    Route::post('retailer-connections', [RetailerConnectionController::class, 'store'])->name('retailer-connections.store');
    Route::post('retailer-connections/{retailerConnection}/verify', RetailerConnectionVerificationController::class)->name('retailer-connections.verify');
    Route::delete('retailer-connections/{retailerConnection}/live-session', RetailerLiveSessionController::class)->name('retailer-connections.live-session.destroy');
    Route::delete('retailer-connections/{retailerConnection}/grant', [RetailerConnectionController::class, 'revokeGrant'])->name('retailer-connections.grant.destroy');
    Route::delete('retailer-connections/{retailerConnection}', [RetailerConnectionController::class, 'destroy'])->name('retailer-connections.destroy');
    Route::get('basket-runs/{basketRun}', [BasketRunController::class, 'show'])->name('basket-runs.show');
    Route::post('basket-runs/{basketRun}/retry', BasketRunRetryController::class)->name('basket-runs.retry');
    Route::post('basket-runs/{basketRun}/restore', BasketRunRestorationController::class)->name('basket-runs.restore');
    Route::post('basket-runs/{basketRun}/review-session', BasketReviewSessionController::class)->name('basket-runs.review-session');
    Route::post('basket-runs/{basketRun}/budget-override', BasketRunBudgetOverrideController::class)->name('basket-runs.budget-override');
    Route::put('basket-runs/{basketRun}/items/{basketRunItem}/product-preference', BasketRunItemProductPreferenceController::class)->name('basket-runs.items.product-preference.update');
    Route::delete('retailer-product-preferences/{preference}', RetailerProductPreferenceController::class)->name('retailer-product-preferences.destroy');
    Route::put('notifications/{notification}/read', NotificationReadController::class)->name('notifications.read');
    Route::post('meal-plans/{mealPlan}/slots', [MealSlotController::class, 'store'])->name('meal-plans.slots.store');
    Route::post('meal-plans/{mealPlan}/milestones', [MealPlanMilestoneController::class, 'store'])->name('meal-plans.milestones.store');
    Route::post('meal-plans/{mealPlan}/safety-review', MealPlanSafetyReviewController::class)->name('meal-plans.safety-review.store');
    Route::post('meal-slots/{mealSlot}/planned-meal', [MealSlotPlannedMealController::class, 'store'])->name('meal-slots.planned-meal.store');
    Route::put('meal-slots/{mealSlot}/participants', [MealSlotParticipantController::class, 'update'])->name('meal-slots.participants.update');
    Route::post('conversations/{conversation}/messages/stream', ConversationMessageStreamController::class)
        ->name('conversations.messages.stream');
    Route::get('message-attachments/{messageAttachment}', MessageAttachmentController::class)
        ->name('message-attachments.show');
    Route::put('messages/{message}/feedback', [MessageFeedbackController::class, 'update'])->name('messages.feedback.update');
    Route::delete('messages/{message}/feedback', [MessageFeedbackController::class, 'destroy'])->name('messages.feedback.destroy');
    Route::put('conversations/{conversation}/feedback', [ConversationFeedbackController::class, 'update'])->name('conversations.feedback.update');
    Route::delete('conversations/{conversation}/feedback', [ConversationFeedbackController::class, 'destroy'])->name('conversations.feedback.destroy');
    Route::put('meal-proposals/{mealProposal}/accept', [MealProposalDecisionController::class, 'accept'])->name('meal-proposals.accept');
    Route::put('meal-proposals/{mealProposal}/reject', [MealProposalDecisionController::class, 'reject'])->name('meal-proposals.reject');
    Route::put('planned-meals/{plannedMeal}/move', PlannedMealMoveController::class)->name('planned-meals.move');
    Route::put('planned-meals/{plannedMeal}', [PlannedMealController::class, 'update'])->name('planned-meals.update');
    Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
    Route::post('recipes', [RecipeController::class, 'store'])->name('recipes.store');
    Route::post('recipes/import', RecipeImportController::class)->name('recipes.import');
    Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');
    Route::post('recipes/{recipe}/versions', [RecipeVersionController::class, 'store'])->name('recipes.versions.store');
    Route::put('preferences/{preference}', [PreferenceController::class, 'update'])->name('preferences.update');
    Route::delete('preferences/{preference}', [PreferenceController::class, 'destroy'])->name('preferences.destroy');
    Route::post('constraints', [ConstraintController::class, 'store'])->name('constraints.store');
    Route::put('constraints/{constraint}', [ConstraintController::class, 'update'])->name('constraints.update');
    Route::delete('constraints/{constraint}', [ConstraintController::class, 'destroy'])->name('constraints.destroy');
    Route::post('team-invitations', [TeamInvitationController::class, 'store'])->name('team-invitations.store');
    Route::put('teams/{team}/current', SwitchTeamController::class)->name('teams.current.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('invitations/{teamInvitation}/accept', [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
});

require __DIR__.'/settings.php';
