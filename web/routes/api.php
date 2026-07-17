<?php

use App\Http\Controllers\Extension\AutomationStepResultController;
use App\Http\Controllers\Extension\AutomationTabController;
use App\Http\Controllers\Extension\AwaitingAutomationRunController;
use App\Http\Controllers\Extension\BrowserConnectionClaimController;
use App\Http\Controllers\Extension\NextAutomationStepController;
use Illuminate\Support\Facades\Route;

Route::prefix('extension')->group(function () {
    Route::post('connections/claim', BrowserConnectionClaimController::class)->middleware('throttle:10,1');

    Route::middleware(['browser-connection', 'throttle:120,1'])->group(function () {
        Route::get('runs/awaiting', AwaitingAutomationRunController::class);
        Route::post('runs/{run}/attach', AutomationTabController::class);
        Route::get('steps/next', NextAutomationStepController::class);
        Route::post('steps/{step}/result', AutomationStepResultController::class);
    });
});
