<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\MealSlotKind;
use App\Models\User;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Tests\Support\MockExpectation;

function mobileTokenFor(User $user, string $device = 'Test iPhone'): string
{
    return $user->createToken("iOS: {$device}", ['mobile'], now()->addDays(30))->plainTextToken;
}

it('registers a household owner and issues a revocable mobile token', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Daniel',
        'email' => 'daniel@example.com',
        'password' => 'CorrectHorseBatteryStaple!9',
        'password_confirmation' => 'CorrectHorseBatteryStaple!9',
        'device_name' => 'Daniel’s iPhone',
    ])->assertCreated()
        ->assertJsonPath('user.name', 'Daniel')
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email']]);

    $user = User::query()->sole();
    expect($user->currentTeam)->not->toBeNull()
        ->and($user->currentTeam->people()->sole()->name)->toBe('Daniel');

    $token = $response->json('token');
    $this->withToken($token)->getJson('/api/v1/session')
        ->assertOk()
        ->assertJsonPath('household.name', 'Daniel family')
        ->assertJsonPath('household.people.0.name', 'Daniel');

    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')
        ->assertOk()
        ->assertJson(['revoked' => true]);
    expect($user->tokens()->count())->toBe(0);
});

it('creates and reloads the first-plan workspace through the mobile API', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $token = mobileTokenFor($user);

    $response = $this->withToken($token)->postJson('/api/v1/meal-plans', [
        'title' => 'First week',
        'starts_on' => '2026-07-27',
        'ends_on' => '2026-08-02',
    ])->assertCreated()
        ->assertJsonPath('workspace.plan.title', 'First week')
        ->assertJsonPath('workspace.household.name', 'The Test Kitchen')
        ->assertJsonPath('workspace.conversation.messages.0.role', 'assistant')
        ->assertJsonStructure([
            'workspace' => ['plan', 'conversation', 'household', 'recipes', 'readiness'],
        ]);

    $plan = $team->mealPlans()->sole();
    $this->withToken($token)->getJson("/api/v1/meal-plans/{$plan->id}/workspace")
        ->assertOk()
        ->assertJsonPath('workspace.plan.id', $response->json('workspace.plan.id'));
});

it('requires and verifies two-factor authentication before issuing a mobile token', function () {
    $user = User::factory()->create([
        'password' => 'CorrectHorseBatteryStaple!9',
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('mobile-test-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ]);
    app(CreateTeamForUser::class)->handle($user, 'Secure family');
    $credentials = [
        'email' => $user->email,
        'password' => 'CorrectHorseBatteryStaple!9',
        'device_name' => 'Secure iPhone',
    ];

    $this->postJson('/api/v1/auth/tokens', $credentials)
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Two-factor authentication is required.',
            'two_factor_required' => true,
        ]);

    MockExpectation::for($this->mock(TwoFactorAuthenticationProvider::class), 'verify')
        ->with('mobile-test-secret', '123456')
        ->andReturnTrue();

    $this->postJson('/api/v1/auth/tokens', [...$credentials, 'code' => '123456'])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at']);
});

it('streams a planning turn and returns durable plan changes through the same contract', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $person = $team->people()->sole();
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->firstOrFail();
    $token = mobileTokenFor($user);

    ChefAgent::fake([
        new ToolCall('slot-call', 'CreatePlanMealSlot', [
            'date' => today()->toDateString(),
            'kind' => 'dinner',
            'participant_ids' => [$person->id],
        ]),
        fn () => new ToolCall('proposal-call', 'CreateMealProposal', [
            'title' => 'Satay chicken',
            'meal_slot_id' => $plan->slots()->sole()->id,
            'summary' => 'Quick chicken satay with rice.',
            'estimated_minutes' => 35,
            'estimated_cost' => 18,
        ]),
        'I have added satay chicken for you to review.',
    ])->preventStrayPrompts();

    $response = $this->withToken($token)
        ->withHeader('Accept', 'application/x-ndjson')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages/stream", [
            'content' => 'Plan satay chicken for dinner.',
            'client_message_id' => fake()->uuid(),
        ]);

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('"type":"delta"')
        ->toContain('"type":"persisted"');

    $this->withToken($token)->getJson("/api/v1/meal-plans/{$plan->id}/workspace")
        ->assertOk()
        ->assertJsonPath('workspace.plan.proposals.0.title', 'Satay chicken');
});

it('returns the updated workspace after native proposal and participant mutations', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $person = $team->people()->sole();
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    $proposal = app(ProposeMeal::class)->handle($plan, $user, 'Miso salmon', $slot);
    $token = mobileTokenFor($user);

    $this->withToken($token)
        ->putJson("/api/v1/meal-slots/{$slot->id}/participants", [
            'participants' => [
                ['person_id' => $person->id, 'servings' => 2],
            ],
            'expected_revision' => $plan->revision,
        ])
        ->assertOk()
        ->assertJsonPath('workspace.plan.slots.0.participants.0.pivot.servings', 2);

    $this->withToken($token)
        ->putJson("/api/v1/meal-proposals/{$proposal->id}/accept")
        ->assertOk()
        ->assertJsonPath('workspace.plan.slots.0.planned_meal.title', 'Miso salmon')
        ->assertJsonPath('workspace.plan.proposals.0.status', 'accepted');
});

it('does not resolve another household’s plans through a mobile token', function () {
    $owner = User::factory()->create();
    $privateTeam = app(CreateTeamForUser::class)->handle($owner, 'Private family');
    $privatePlan = app(StartMealPlan::class)->handle($privateTeam, $owner, today(), today()->addDay());
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->withToken(mobileTokenFor($outsider))
        ->getJson("/api/v1/meal-plans/{$privatePlan->id}/workspace")
        ->assertNotFound();
});
