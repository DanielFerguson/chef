<?php

use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps public launch information accessible at mobile width', function () {
    visit(route('privacy'))
        ->resize(390, 844)
        ->wait(0.5)
        ->assertSee('Privacy policy')
        ->assertScript('() => document.querySelectorAll("main").length === 1')
        ->assertScript('() => document.querySelectorAll("[aria-labelledby]").length === [...document.querySelectorAll("[aria-labelledby]")].filter((element) => element.getAttribute("aria-labelledby").split(/\\s+/).every((id) => document.getElementById(id))).length')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('keeps family privacy controls labelled and usable at mobile width', function () {
    $user = User::factory()->create();
    app(CreateTeamForUser::class)->handle($user, 'Privacy family');
    $this->actingAs($user);

    visit(route('data-privacy.edit'))
        ->resize(390, 844)
        ->wait(0.5)
        ->assertSee('Data and privacy')
        ->assertPresent('#optional-consent')
        ->assertPresent('#permissions')
        ->assertPresent('#usage-audit')
        ->assertPresent('#retention')
        ->assertPresent('#export-data')
        ->assertScript('() => document.querySelectorAll("[aria-labelledby]").length === [...document.querySelectorAll("[aria-labelledby]")].filter((element) => element.getAttribute("aria-labelledby").split(/\\s+/).every((id) => document.getElementById(id))).length')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});
