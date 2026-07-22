<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_root_route_to_login()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_from_the_root_route_to_the_dashboard()
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('home'));

        $response->assertRedirect(route('dashboard'));
    }
}
