<?php

namespace Tests\Feature;

use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
