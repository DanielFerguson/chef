<?php

use App\Actions\Privacy\ExpireOperationalEvents;
use App\Actions\Privacy\RecordConsentChoice;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\Conversation;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Support\OperationalMetrics;
use App\Support\UsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('records product analytics only while the current user has opted in', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Measured family');
    $metrics = app(OperationalMetrics::class);

    expect($metrics->recordProduct($team, $user, 'first_plan_started'))->toBeNull()
        ->and(OperationalEvent::query()->count())->toBe(0);

    app(RecordConsentChoice::class)->handle($team, $user, 'product_analytics', 'granted');
    expect($metrics->recordProduct($team, $user, 'first_plan_started'))->not->toBeNull()
        ->and(OperationalEvent::query()->where('category', 'product')->count())->toBe(1);

    app(RecordConsentChoice::class)->handle($team, $user, 'product_analytics', 'revoked');
    expect($metrics->recordProduct($team, $user, 'shopping_list_completed'))->toBeNull()
        ->and(OperationalEvent::query()->where('category', 'product')->count())->toBe(1);
});

it('blocks an AI stream before creating a message when the family rate limit is reached', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Rate limited family');
    $conversation = Conversation::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
    ]);
    config(['chef.quotas.ai_requests_per_minute' => 1]);
    RateLimiter::hit("ai:team:{$team->id}", 60);

    $this->actingAs($user)
        ->postJson(route('conversations.messages.stream', $conversation), [
            'content' => 'Plan dinner',
            'client_message_id' => (string) Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('usage');

    expect($conversation->messages()->count())->toBe(0);
});

it('enforces token, cost, automation, and voice ceilings per family', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Bounded family');
    $guard = app(UsageGuard::class);

    OperationalEvent::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'category' => 'ai',
        'name' => 'conversation_stream',
        'status' => 'completed',
        'prompt_tokens' => 90,
        'completion_tokens' => 10,
        'estimated_cost_microusd' => 2_000_000,
        'occurred_at' => now(),
    ]);

    config([
        'chef.quotas.ai_tokens_per_month' => 100,
        'chef.quotas.ai_cost_usd_per_month' => 2,
        'chef.quotas.automation_runs_per_day' => 0,
        'chef.quotas.voice_sessions_per_day' => 0,
    ]);

    expect(fn () => $guard->assertAiAllowed($team))->toThrow(ValidationException::class)
        ->and(fn () => $guard->assertAutomationAllowed($team))->toThrow(ValidationException::class)
        ->and(fn () => $guard->assertVoiceAllowed($team))->toThrow(ValidationException::class);
});

it('expires content-free operational events on the configured schedule', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Audit family');
    config(['chef.retention.audit_days' => 30]);

    foreach ([now()->subDays(31), now()->subDays(29)] as $occurredAt) {
        OperationalEvent::query()->create([
            'team_id' => $team->id,
            'category' => 'automation',
            'name' => 'cart_preparation_started',
            'status' => 'completed',
            'occurred_at' => $occurredAt,
        ]);
    }

    expect(app(ExpireOperationalEvents::class)->handle())->toBe(1)
        ->and(OperationalEvent::query()->count())->toBe(1);
});

it('publishes the privacy, terms, security, help, and release candidate pages', function () {
    config([
        'chef.legal.operator' => 'Chef Test Pty Ltd',
        'chef.legal.contact_address' => '1 Test Street, Melbourne',
        'chef.legal.processing_countries' => 'Australia',
        'chef.support.email' => 'support@chef.test',
    ]);

    $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('privacy')
        ->where('operator', 'Chef Test Pty Ltd')
        ->where('supportEmail', 'support@chef.test'));
    $this->get(route('terms'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('terms'));
    $this->get(route('security-and-privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('security-and-privacy'));
    $this->get(route('help'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('help'));
    $this->get(route('release-notes'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('release-notes'));
});

it('provides a stable first-plan handoff for the public marketing site', function () {
    $this->get(route('start'))->assertRedirect(route('register'));

    $user = User::factory()->create();
    app(CreateTeamForUser::class)->handle($user, 'Marketing handoff family');

    $this->actingAs($user)->get(route('start'))->assertRedirect(route('dashboard'));
});

it('applies strict production security headers without disabling microphone fallback', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), payment=(), usb=(), microphone=(self)');

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect($policy)->toContain("script-src 'self' 'nonce-")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->not->toContain("script-src 'unsafe-inline'")
        ->not->toContain("script-src 'unsafe-eval'");
});

it('renders a usable production failure page', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/missing-release-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 404));
});

it('rejects active URL schemes from recipe source links', function () {
    $user = User::factory()->create();
    app(CreateTeamForUser::class)->handle($user, 'Safe links family');

    $this->actingAs($user)->post(route('recipes.store'), [
        'title' => 'Soup',
        'servings' => 2,
        'source_url' => 'javascript:alert(1)',
        'ingredients' => [['name' => 'Water']],
        'steps' => [['instruction' => 'Simmer']],
    ])->assertSessionHasErrors('source_url');
});
