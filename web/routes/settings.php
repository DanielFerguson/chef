<?php

use App\Http\Controllers\Settings\ConsentController;
use App\Http\Controllers\Settings\DataPrivacyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TeamDataExportController;
use App\Http\Controllers\Settings\TeamDeletionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::middleware(['auth', 'verified', 'current-team'])->group(function () {
    Route::get('settings/data-privacy', [DataPrivacyController::class, 'edit'])
        ->name('data-privacy.edit');
    Route::get('settings/data-export', TeamDataExportController::class)
        ->middleware(RequirePassword::class)
        ->name('data-privacy.export');
    Route::put('settings/consent', [ConsentController::class, 'update'])
        ->name('data-privacy.consent.update');
    Route::delete('settings/family', TeamDeletionController::class)
        ->name('data-privacy.team.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
