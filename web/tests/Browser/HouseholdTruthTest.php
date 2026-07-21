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
            sourceMessage: $message,
            evidenceQuote: $message->content,
        );
    }

    expect($tahlia->preferences()->pluck('source_message_id')->all())
        ->each->toBe($message->id);

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
                && list.querySelectorAll('[data-truth-actions]').length === 3
                && list.querySelectorAll('button[title=\"View source message\"]').length === 3;
        }")
        ->assertVisible('button[aria-label="View source for Mushrooms"]')
        ->click('button[aria-label="View source for Mushrooms"]')
        ->wait(1)
        ->assertScript("() => {
            const viewport = document.querySelector('[data-slot=message-scroller-viewport]');
            const source = document.querySelector('[data-message-id=\"{$message->id}\"]');
            const viewportBounds = viewport.getBoundingClientRect();
            const sourceBounds = source.getBoundingClientRect();
            const announcement = [...document.querySelectorAll('[role=status]')]
                .some((item) => item.textContent.includes('Source message shown.'));
            return sourceBounds.top >= viewportBounds.top
                && sourceBounds.bottom <= viewportBounds.bottom
                && source.dataset.sourceTarget === 'true'
                && document.activeElement === source
                && announcement;
        }")
        ->assertNoJavaScriptErrors();
});

it('keeps unreviewed safety distinct from none reported at tablet width', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Safety family');
    $tabletPlan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $this->actingAs($user);

    visit(route('meal-plans.show', $tabletPlan))
        ->resize(1004, 900)
        ->assertPresent('button[aria-label="Open plan details"]')
        ->click('button[aria-label="Open plan details"]')
        ->assertSee('Safety details have not been reviewed yet')
        ->assertSee('Confirm none reported')
        ->assertNoJavaScriptErrors();
});

it('keeps unreviewed safety distinct from none reported at mobile width', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Safety family');
    $mobilePlan = app(StartMealPlan::class)->handle($team, $user, today()->addDays(3), today()->addDays(5));
    $this->actingAs($user);

    visit(route('meal-plans.show', $mobilePlan))
        ->resize(390, 844)
        ->click('button[aria-label="Open plan details"]')
        ->assertSee('Safety details have not been reviewed yet')
        ->assertSee('Confirm none reported')
        ->assertNoJavaScriptErrors();
});
