<?php

use App\Actions\Privacy\ExpireConversationContent;
use App\Actions\Privacy\ExportTeamData;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Actions\Teams\InviteUserToTeam;
use App\Enums\BrowserConnectionStatus;
use App\Models\BrowserConnection;
use App\Models\ConsentRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows current optional choices and permission counts to a signed-in family member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Privacy family');
    ConsentRecord::query()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'kind' => 'product_analytics',
        'status' => 'granted',
        'purpose' => 'Optional analytics',
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('data-privacy.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/data-privacy')
            ->where('team.name', 'Privacy family')
            ->where('consents.product_analytics.status', 'granted')
            ->where('permissions.active_browser_connections', 0)
            ->where('permissions.active_voice_sessions', 0)
            ->where('canDeleteTeam', true));
});

it('records an append-only audit when optional consent changes', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Consent family');

    $this->actingAs($owner)
        ->put(route('data-privacy.consent.update'), [
            'kind' => 'product_analytics',
            'status' => 'granted',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->put(route('data-privacy.consent.update'), [
            'kind' => 'product_analytics',
            'status' => 'revoked',
        ])
        ->assertSessionHasNoErrors();

    expect($team->consentRecords()->pluck('status')->all())->toBe(['granted', 'revoked']);
});

it('redacts expired conversation content idempotently', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Retention family');
    $conversation = Conversation::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $owner->id]);
    $message = Message::factory()->create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'user_id' => $owner->id,
        'content' => 'Old private conversation content',
        'metadata' => ['provider' => 'private detail'],
        'created_at' => now()->subDays(31),
    ]);
    config(['chef.retention.conversations_days' => 30]);

    expect(app(ExpireConversationContent::class)->handle())->toBe(1)
        ->and($message->refresh()->content)->toBe('Conversation content expired after 30 days.')
        ->and($message->content_redacted_at)->not->toBeNull()
        ->and($message->metadata)->toBeNull()
        ->and(app(ExpireConversationContent::class)->handle())->toBe(0);
});

it('exports every team-scoped table and removes credentials and screenshot paths', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Export family');
    $conversation = Conversation::factory()->create(['team_id' => $team->id, 'created_by_user_id' => $owner->id]);
    Message::factory()->create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'user_id' => $owner->id,
        'content' => 'Our exportable family message',
    ]);
    app(InviteUserToTeam::class)->handle($team, $owner, 'invited@example.test');
    BrowserConnection::query()->create([
        'uuid' => (string) Str::uuid(),
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Family browser',
        'status' => BrowserConnectionStatus::Pending,
        'pairing_code_hash' => hash('sha256', 'pairing'),
        'token_hash' => hash('sha256', 'token'),
        'allowed_origins' => ['https://www.coles.com.au'],
        'expires_at' => now()->addHour(),
    ]);

    $export = app(ExportTeamData::class)->handle($team, $owner);

    expect($export['format'])->toBe('chef-team-export.v1')
        ->and(data_get($export, 'data.messages.0.content'))->toBe('Our exportable family message')
        ->and(data_get($export, 'data.browser_connections.0.pairing_code_hash'))->toBeNull()
        ->and(data_get($export, 'data.browser_connections.0.token_hash'))->toBeNull()
        ->and(data_get($export, 'data.team_invitations.0.token'))->toBeNull();

    $teamScopedTables = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table): bool => in_array('team_id', Schema::getColumnListing($table), true))
        ->sort()
        ->values()
        ->all();
    $declaredTables = collect(ExportTeamData::TEAM_TABLES)->sort()->values()->all();

    expect($declaredTables)->toBe($teamScopedTables);
});

it('lets only an owner delete a family after exact name and password confirmation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Delete family');
    app(AddUserToTeam::class)->handle($team, $member);

    $this->actingAs($member)
        ->delete(route('data-privacy.team.destroy'), [
            'team_name' => 'Delete family',
            'password' => 'password',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('data-privacy.team.destroy'), [
            'team_name' => 'delete family',
            'password' => 'password',
        ])
        ->assertSessionHasErrors('team_name');

    $this->actingAs($owner)
        ->delete(route('data-privacy.team.destroy'), [
            'team_name' => 'Delete family',
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Team::query()->find($team->id))->toBeNull()
        ->and($owner->refresh()->currentTeam->name)->toBe('New family')
        ->and($member->refresh()->current_team_id)->toBeNull();
});

it('blocks account deletion until ownership of a shared family is resolved', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Shared family');
    app(AddUserToTeam::class)->handle($team, $member);

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('account');

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh())->not->toBeNull()
        ->and($team->fresh())->not->toBeNull();
});

it('deletes sole-owner family data with the account', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Solo family');

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($owner->fresh())->toBeNull()
        ->and($team->fresh())->toBeNull();
});
