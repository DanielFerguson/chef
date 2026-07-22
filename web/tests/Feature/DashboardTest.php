<?php

namespace Tests\Feature;

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        app(CreateTeamForUser::class)->handle($user, 'Test family');
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('household.name', 'Test family')
            ->has('household.people', 1));
    }

    public function test_today_uses_one_canonical_confirmed_plan_when_date_ranges_overlap()
    {
        Queue::fake();
        $user = User::factory()->create();
        $team = app(CreateTeamForUser::class)->handle($user, 'Test family');
        $older = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Older plan');
        $newer = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Current plan');

        foreach ([[$older, 'Old dinner'], [$newer, 'Current dinner']] as [$plan, $title]) {
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
                title: $title,
            );
        }

        $older->forceFill(['planning_confirmed_at' => now()->subMinute()])->save();
        $newer->forceFill(['planning_confirmed_at' => now()])->save();

        $this->withoutVite()->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('today.meals', 1)
                ->where('today.meals.0.meal_plan_id', $newer->id)
                ->where('today.meals.0.title', 'Current dinner')
                ->where('today.journey.plan_id', $newer->id)
                ->where('today.journey.phase', 'plan_confirmed')
                ->where('recentMealPlans.0.id', $newer->id)
                ->where('recentMealPlans.0.phase', 'Confirmed')
                ->where('recentMealPlans.1.id', $older->id)
                ->where('recentMealPlans.1.phase', 'Superseded'));
    }
}
