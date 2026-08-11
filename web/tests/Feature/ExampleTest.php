<?php

use App\Models\User;

test('guests are redirected from the root route to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('authenticated users are redirected from the root route to the dashboard', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
