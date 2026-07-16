<?php

use App\Http\Controllers\ConstraintController;
use App\Http\Controllers\ConversationMessageStreamController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealProposalDecisionController;
use App\Http\Controllers\MealSlotController;
use App\Http\Controllers\PlannedMealMoveController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\SwitchTeamController;
use App\Http\Controllers\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'current-team'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('meal-plans', [MealPlanController::class, 'store'])->name('meal-plans.store');
    Route::get('meal-plans/{mealPlan}', [MealPlanController::class, 'show'])->name('meal-plans.show');
    Route::post('meal-plans/{mealPlan}/slots', [MealSlotController::class, 'store'])->name('meal-plans.slots.store');
    Route::post('conversations/{conversation}/messages/stream', ConversationMessageStreamController::class)
        ->name('conversations.messages.stream');
    Route::put('meal-proposals/{mealProposal}/accept', [MealProposalDecisionController::class, 'accept'])->name('meal-proposals.accept');
    Route::put('meal-proposals/{mealProposal}/reject', [MealProposalDecisionController::class, 'reject'])->name('meal-proposals.reject');
    Route::put('planned-meals/{plannedMeal}/move', PlannedMealMoveController::class)->name('planned-meals.move');
    Route::put('preferences/{preference}', [PreferenceController::class, 'update'])->name('preferences.update');
    Route::delete('preferences/{preference}', [PreferenceController::class, 'destroy'])->name('preferences.destroy');
    Route::put('constraints/{constraint}', [ConstraintController::class, 'update'])->name('constraints.update');
    Route::delete('constraints/{constraint}', [ConstraintController::class, 'destroy'])->name('constraints.destroy');
    Route::post('team-invitations', [TeamInvitationController::class, 'store'])->name('team-invitations.store');
    Route::put('teams/{team}/current', SwitchTeamController::class)->name('teams.current.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('invitations/{teamInvitation}', [TeamInvitationController::class, 'show'])->name('team-invitations.show');
    Route::post('invitations/{teamInvitation}/accept', [TeamInvitationController::class, 'accept'])->name('team-invitations.accept');
});

require __DIR__.'/settings.php';
