<?php

use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('combines family switching and account actions in one sidebar control', function () {
    $user = User::factory()->create([
        'name' => 'Daniel',
        'email' => 'daniel@example.test',
    ]);
    app(CreateTeamForUser::class)->handle($user, 'Daniel & Tahlia');
    $this->actingAs($user);

    visit(route('dashboard'))->on()->desktop()
        ->assertCount('[data-test="sidebar-family-account-button"]', 1)
        ->assertSeeIn('[data-sidebar-family-name]', 'Daniel & Tahlia')
        ->assertSeeIn('[data-sidebar-user-name]', 'Daniel')
        ->click('[data-test="sidebar-family-account-button"]')
        ->assertPresent('[data-test="sidebar-family-account-menu"]')
        ->assertSee('daniel@example.test')
        ->assertSee('Family workspace')
        ->assertSee('Settings')
        ->assertSee('Log out')
        ->keys('[data-test="sidebar-family-account-menu"]', 'Escape')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertCount('[data-test="sidebar-family-account-button"]', 1)
        ->click('[data-test="sidebar-family-account-button"]')
        ->assertPresent('[data-test="sidebar-family-account-menu"]')
        ->assertNoJavaScriptErrors();
});
