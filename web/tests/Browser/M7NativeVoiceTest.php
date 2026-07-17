<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('keeps microphone consent just in time and preserves the typed fallback', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Browser voice family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $this->actingAs($user);
    $page = visit(route('meal-plans.show', $plan, absolute: false))->on()->mobile();
    $page->wait(0.5);

    $page->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertPresent('button[aria-label="Start voice conversation"]')
        ->assertDontSee('Allow microphone')
        ->click('button[aria-label="Start voice conversation"]')
        ->wait(0.5)
        ->assertSee('Talk with Chef')
        ->assertSee('Spoken turns and Chef\'s replies are saved in this conversation.')
        ->assertSee('typing always remains available');

    $page->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertNoJavaScriptErrors();

    expect(VoiceSession::query()->count())->toBe(0);
});

it('exercises active voice controls without a live provider', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Active browser voice family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $this->actingAs($user);
    $page = visit(URL::temporarySignedRoute('meal-plans.show', now()->addMinute(), [
        'mealPlan' => $plan,
        '_voice_test' => 1,
    ], absolute: false))->on()->desktop();
    $page->wait(0.5);

    $page->assertSee('Listening…')
        ->assertPresent('button[aria-label="Mute microphone"]')
        ->assertPresent('button[aria-label="End voice conversation"]')
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->click('button[aria-label="Mute microphone"]')
        ->assertSee('Microphone muted')
        ->assertPresent('button[aria-label="Unmute microphone"]')
        ->click('button[aria-label="Unmute microphone"]')
        ->assertSee('Listening…')
        ->click('button[aria-label="End voice conversation"]')
        ->assertPresent('button[aria-label="Start voice conversation"]')
        ->assertPresent('textarea[aria-label="Message Chef"]')
        ->assertNoJavaScriptErrors();

    expect(VoiceSession::query()->count())->toBe(0);
});
