<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SwitchTeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'current-team'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::put('teams/{team}/current', SwitchTeamController::class)->name('teams.current.update');
});

require __DIR__.'/settings.php';
