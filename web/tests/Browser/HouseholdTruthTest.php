<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Households\CreateHouseholdPerson;
use App\Actions\Households\RecordPreference;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('groups stated preferences under each household person', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Daniel & Tahlia');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $message = app(CreateUserMessage::class)->handle(
        $plan->conversations->first(),
        $user,
        'Tahlia avoids mushrooms, raw tomatoes, and fish.',
        (string) Str::uuid(),
    );
    $tahlia = app(CreateHouseholdPerson::class)->handle($team, $user, 'Tahlia', $message);

    foreach (['Mushrooms', 'Raw tomatoes', 'Fish'] as $subject) {
        app(RecordPreference::class)->handle(
            $team,
            $user,
            $subject,
            PreferenceSentiment::Dislike,
            PreferenceProvenance::Stated,
            $tahlia,
        );
    }

    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertPresent('[data-truth-owner="Tahlia"] > h4')
        ->assertPresent('ul[aria-label="Tahlia stated preferences"]')
        ->assertScript("() => {
            const list = document.querySelector('ul[aria-label=\"Tahlia stated preferences\"]');
            return list?.children.length === 3
                && list.textContent.includes('Avoid Mushrooms')
                && list.textContent.includes('Avoid Raw tomatoes')
                && list.textContent.includes('Avoid Fish')
                && !list.textContent.includes('stated')
                && list.querySelectorAll('[data-truth-actions]').length === 3;
        }")
        ->assertNoJavaScriptErrors();
});
