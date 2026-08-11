<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Ai\Testing\DeterministicMealPlanRecipeDrafter;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;

it('shows one whole-plan approval instead of serial meal decisions on desktop and narrow screens', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay(), 'Weeknight plan');

    foreach (['Chicken tacos', 'Vegetable pasta'] as $offset => $title) {
        $slot = app(CreateMealSlot::class)->handle(
            $plan,
            $user,
            today()->addDays($offset),
            MealSlotKind::Dinner,
            $team->people,
        );
        app(ProposeMeal::class)->handle(
            $plan,
            $user,
            $title,
            $slot,
            'A practical family dinner.',
            30,
            12.5,
        );
    }

    $matchesAssistantTypeset = <<<'JS'
        () => {
            const assistant = document.querySelector('[data-message-role="assistant"]');
            const planState = document.querySelector('[data-plan-state-copy]');

            if (! assistant || ! planState) {
                return false;
            }

            const assistantStyle = getComputedStyle(assistant);
            const planStateStyle = getComputedStyle(planState);

            return assistantStyle.fontFamily === planStateStyle.fontFamily
                && assistantStyle.fontSize === planStateStyle.fontSize
                && assistantStyle.lineHeight === planStateStyle.lineHeight;
        }
        JS;

    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertPresent('[data-plan-approval]')
        ->assertSee('Your plan is ready to approve')
        ->assertScript($matchesAssistantTypeset)
        ->assertSee('Chicken tacos')
        ->assertSee('Vegetable pasta')
        ->assertSee('Approve plan & prepare recipes')
        ->resize(390, 844)
        ->assertPresent('[data-plan-approval]')
        ->assertScript($matchesAssistantTypeset)
        ->assertSee('Approve plan & prepare recipes')
        ->assertNoJavaScriptErrors();
});

it('recovers a failed recipe batch on a narrow screen and opens the prepared recipe', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today(), 'Recipe recovery');
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Custom,
        title: 'Lemon chicken tray bake',
    );
    $this->app->bind(MealPlanRecipeDrafter::class, fn () => new class implements MealPlanRecipeDrafter
    {
        public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
        {
            throw new RuntimeException('Temporary recipe provider failure.');
        }
    });
    app(ApproveMealPlan::class)->handle($plan, $user);
    $this->app->bind(MealPlanRecipeDrafter::class, DeterministicMealPlanRecipeDrafter::class);
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))
        ->resize(390, 844)
        ->assertSee('The completed plan needs another try')
        ->assertSee('Retry recipes')
        ->assertDontSee('Shopping')
        ->pressAndWaitFor('Retry recipes')
        ->assertSee('Plan and recipes are ready')
        ->pressAndWaitFor('View recipes')
        ->assertSee('Lemon chicken tray bake')
        ->assertNoJavaScriptErrors();
});

it('visually distinguishes the selected plan when titles are identical', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $first = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Next week');
    $second = app(StartMealPlan::class)->handle($team, $user, today()->addDays(7), today()->addDays(13), 'Next week');
    $firstHref = route('meal-plans.show', $first, absolute: false);
    $secondHref = route('meal-plans.show', $second, absolute: false);
    $this->actingAs($user);

    visit(route('meal-plans.show', $first))->on()->desktop()
        ->assertPresent("a[href=\"{$firstHref}\"][aria-current=\"page\"] [data-current-plan-indicator]")
        ->assertNotPresent("a[href=\"{$secondHref}\"] [data-current-plan-indicator]")
        ->assertAttributeMissing("a[href=\"{$secondHref}\"]", 'aria-current')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertPresent("a[href=\"{$firstHref}\"][aria-current=\"page\"] [data-current-plan-indicator]")
        ->assertNoJavaScriptErrors();
});

it('renames and deletes the active plan from the sidebar menu', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Original plan');
    $usesReadableDarkDestructiveText = <<<'JS'
        () => {
            const item = document.querySelector('[data-variant="destructive"]');

            if (! item) {
                return false;
            }

            const rootStyle = getComputedStyle(document.documentElement);
            const rendered = getComputedStyle(item).color.replace(/\s+/g, ' ');
            const textToken = rootStyle
                .getPropertyValue('--destructive-text')
                .trim()
                .replace(/\s+/g, ' ');
            const backgroundToken = rootStyle
                .getPropertyValue('--destructive')
                .trim()
                .replace(/\s+/g, ' ');
            const tokenProbe = document.createElement('span');
            tokenProbe.style.color = textToken;
            document.body.append(tokenProbe);
            const renderedTextToken = getComputedStyle(tokenProbe)
                .color
                .replace(/\s+/g, ' ');
            tokenProbe.remove();
            const lightness = (value) => Number(
                value.match(/^oklch\(([\d.]+)/)?.[1] ?? Number.NaN,
            );

            return rendered === renderedTextToken
                && lightness(textToken) >= 0.7
                && lightness(textToken) > lightness(backgroundToken);
        }
        JS;
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertScript("() => {
            document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = 'dark';

            return true;
        }")
        ->rightClick("[data-plan-context-menu=\"{$plan->id}\"]")
        ->assertSee('Rename')
        ->assertSee('Delete')
        ->assertScript($usesReadableDarkDestructiveText)
        ->click('Rename')
        ->type("#plan-{$plan->id}-title", 'Weeknight favourites')
        ->click('Save')
        ->assertSee('Weeknight favourites')
        ->click('[aria-label="Open actions for Weeknight favourites"]')
        ->assertScript($usesReadableDarkDestructiveText)
        ->click('Delete')
        ->assertSee('This permanently deletes the plan')
        ->click('Delete plan')
        ->assertSee('Welcome to Chef')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
});

it('keeps plan actions reachable in the narrow sidebar drawer', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2), 'Mobile plan');
    $this->actingAs($user);

    visit(route('dashboard'))
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertPresent('[aria-label="Open actions for Mobile plan"]')
        ->click('[aria-label="Open actions for Mobile plan"]')
        ->assertSee('Rename')
        ->assertSee('Delete')
        ->assertNoJavaScriptErrors();
});
