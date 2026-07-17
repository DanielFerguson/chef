<?php

use App\Support\M8LaunchEvidence;
use App\Support\ReleaseRuntimeProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-18T10:00:00+10:00');
    config([
        'app.release' => 'release-sha-123',
        'app.url' => 'https://app.cheffamily.com',
        'chef.release.evidence_key' => str_repeat('release-secret-', 3),
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function m8LaunchManifest(): array
{
    $truths = fn (array $names): array => array_fill_keys($names, true);
    $probe = $truths([
        'release_check', 'database', 'cache', 'object_storage', 'queue_worker',
        'scheduler', 'broadcast_tenancy', 'nightwatch_web', 'nightwatch_worker',
        'mail_delivery', 'marketing_start_link', 'marketing_login_link',
    ]);
    $gate = fn (array $metrics, string $completedAt = '2026-07-18T08:00:00+10:00'): array => [
        'result' => 'passed',
        'completed_at' => $completedAt,
        'operator' => 'Release owner',
        'evidence_url' => 'https://evidence.cheffamily.com/releases/release-sha-123',
        'evidence_sha256' => str_repeat('a', 64),
        'metrics' => $metrics,
    ];

    return [
        'schema' => M8LaunchEvidence::SCHEMA,
        'release' => 'release-sha-123',
        'deployment_origin' => 'https://app.cheffamily.com',
        'approved_by' => 'Version 1 release owner',
        'approved_at' => '2026-07-18T09:00:00+10:00',
        'gates' => [
            'staging_probe' => $gate($probe, '2026-07-17T08:00:00+10:00'),
            'production_probe' => $gate($probe),
            'backup_restore' => $gate([
                ...$truths(['restore_completed', 'schema_current', 'row_counts_reconciled', 'private_files_verified', 'source_untouched']),
                'recovery_point_hours' => 12,
                'recovery_time_minutes' => 90,
            ], '2026-07-10T08:00:00+10:00'),
            'privacy_legal' => $gate($truths(['privacy_approved', 'terms_approved', 'operator_final', 'processing_countries_final', 'support_route_tested']), '2026-07-10T08:00:00+10:00'),
            'data_controls' => $gate($truths(['tenant_isolation', 'team_deletion', 'account_deletion', 'account_export', 'conversation_expiry', 'screenshot_expiry', 'audit_expiry'])),
            'woolworths_handoff' => $gate([
                ...$truths(['cart_persistence', 'selected_store', 'pause', 'takeover', 'pre_checkout_stop']),
                'attempts' => 4,
                'households' => 2,
                'success_rate' => 1.0,
            ]),
            'coles_handoff' => $gate([
                ...$truths(['cart_persistence', 'selected_store', 'pause', 'takeover', 'pre_checkout_stop']),
                'attempts' => 3,
                'households' => 2,
                'success_rate' => 0.8,
            ]),
            'realtime_microphone' => $gate([
                ...$truths(['permission_disclosure', 'revocation', 'interruption', 'reconnect', 'mute', 'transcript', 'typed_fallback']),
                'sessions' => 5,
                'success_rate' => 0.8,
            ]),
            'private_beta' => $gate([
                ...$truths(['medium_issues_decided', 'support_sla_met']),
                'households' => 4,
                'mobile_households' => 2,
                'first_plan_success_rate' => 0.8,
                'shopping_success_rate' => 0.8,
                'cooking_success_rate' => 1.0,
                'first_plan_median_minutes' => 12,
                'critical_open' => 0,
                'high_open' => 0,
            ], '2026-07-10T08:00:00+10:00'),
            'production_replay' => $gate([
                ...$truths(['previous_milestones_passed', 'mysql_ci_passed', 'full_suite_passed', 'browser_suite_passed', 'dependency_audits_clear', 'security_review_clear', 'marketing_production_build_passed', 'extension_production_build_passed', 'ci_required_jobs_passed', 'cost_ceiling_configured', 'automation_failure_rate_measured']),
                'automation_failure_rate' => 0.1,
                'monthly_ai_cost_per_active_family_usd' => 8.5,
                'monthly_ai_cost_ceiling_usd' => 25,
            ]),
        ],
    ];
}

function configureM8ProductionRelease(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:test-key',
        'app.maintenance.driver' => 'cache',
        'database.default' => 'mysql',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'session.secure' => true,
        'session.encrypt' => true,
        'broadcasting.default' => 'reverb',
        'filesystems.default' => 's3',
        'chef.storage.automation_screenshots_disk' => 's3',
        'ai.providers.openai.key' => 'test-openai-key',
        'ai.providers.openai.store' => false,
        'mail.default' => 'postmark',
        'services.nightwatch.token' => 'test-nightwatch-token',
        'nightwatch.capture_request_payload' => false,
        'chef.support.email' => 'support@cheffamily.com',
        'chef.support.privacy_url' => 'https://app.cheffamily.com/privacy',
        'chef.support.terms_url' => 'https://app.cheffamily.com/terms',
        'chef.legal.operator' => 'Chef Family Pty Ltd',
        'chef.legal.contact_address' => '1 Family Street, Melbourne VIC 3000',
        'chef.legal.processing_countries' => 'Australia and United States',
        'chef.retention.automation_screenshots_hours' => 24,
        'chef.retention.conversations_days' => 730,
        'chef.retention.audit_days' => 730,
        'chef.quotas.ai_input_usd_per_million' => 1.25,
        'chef.quotas.ai_output_usd_per_million' => 10,
        'chef.quotas.ai_cost_usd_per_month' => 25,
        'chef.quotas.ai_tokens_per_month' => 2_000_000,
    ]);
}

it('accepts only a complete signed content-free M8 evidence manifest', function () {
    $evidence = app(M8LaunchEvidence::class);
    $manifest = m8LaunchManifest();
    $manifest['signature'] = $evidence->sign($manifest);

    expect($evidence->payloadFailures($manifest))->toBe([])
        ->and($evidence->failures($manifest))->toBe([]);
});

it('rejects waivers, weak measured outcomes, extra content, and tampering', function () {
    $evidence = app(M8LaunchEvidence::class);
    $manifest = m8LaunchManifest();
    $manifest['signature'] = $evidence->sign($manifest);
    $manifest['gates']['woolworths_handoff']['result'] = 'waived';
    $manifest['gates']['woolworths_handoff']['metrics']['success_rate'] = 0.5;
    $manifest['gates']['woolworths_handoff']['notes'] = 'Household conversation content must not be copied here.';

    $keys = collect($evidence->failures($manifest))->pluck('key');

    expect($keys)->toContain('gates.woolworths_handoff.result')
        ->toContain('gates.woolworths_handoff.metrics.success_rate')
        ->toContain('gates.woolworths_handoff.fields')
        ->toContain('signature');
});

it('rejects stale production proof and evidence links containing query secrets', function () {
    $manifest = m8LaunchManifest();
    $manifest['gates']['production_probe']['completed_at'] = '2026-07-10T08:00:00+10:00';
    $manifest['gates']['backup_restore']['evidence_url'] = 'https://evidence.cheffamily.com/restore?token=secret';

    $keys = collect(app(M8LaunchEvidence::class)->payloadFailures($manifest))->pluck('key');

    expect($keys)->toContain('gates.production_probe.completed_at')
        ->toContain('gates.backup_restore.evidence_url');
});

it('requires strict timestamps, bounded labels, and typed numeric metrics', function () {
    $manifest = m8LaunchManifest();
    $manifest['approved_at'] = 'yesterday';
    $manifest['approved_by'] = "Release owner\nprivate note";
    $manifest['gates']['coles_handoff']['metrics']['attempts'] = '3';
    $manifest['gates']['woolworths_handoff']['metrics']['households'] = 2.5;
    $manifest['gates']['realtime_microphone']['metrics']['success_rate'] = 1.5;

    $keys = collect(app(M8LaunchEvidence::class)->payloadFailures($manifest))->pluck('key');

    expect($keys)->toContain('approved_at')
        ->toContain('approved_by')
        ->toContain('gates.coles_handoff.metrics.attempts')
        ->toContain('gates.woolworths_handoff.metrics.households')
        ->toContain('gates.realtime_microphone.metrics.success_rate');
});

it('rejects an otherwise valid approval after its 24 hour release window', function () {
    $manifest = m8LaunchManifest();
    $manifest['approved_at'] = '2026-07-16T09:00:00+10:00';

    $keys = collect(app(M8LaunchEvidence::class)->payloadFailures($manifest))->pluck('key');

    expect($keys)->toContain('approved_at');
});

it('enforces recovery, automation reliability, and AI cost bounds', function () {
    $manifest = m8LaunchManifest();
    $manifest['gates']['backup_restore']['metrics']['recovery_point_hours'] = 25;
    $manifest['gates']['backup_restore']['metrics']['recovery_time_minutes'] = 241;
    $manifest['gates']['production_replay']['metrics']['automation_failure_rate'] = 0.21;
    $manifest['gates']['production_replay']['metrics']['monthly_ai_cost_per_active_family_usd'] = 26;

    $keys = collect(app(M8LaunchEvidence::class)->payloadFailures($manifest))->pluck('key');

    expect($keys)->toContain('gates.backup_restore.metrics.recovery_point_hours')
        ->toContain('gates.backup_restore.metrics.recovery_time_minutes')
        ->toContain('gates.production_replay.metrics.automation_failure_rate')
        ->toContain('gates.production_replay.metrics.monthly_ai_cost_per_active_family_usd');
});

it('signs a valid manifest and approves only the exact ready deployment', function () {
    configureM8ProductionRelease();
    $this->app->detectEnvironment(fn () => 'production');
    Storage::fake('s3');

    $runtime = Mockery::mock(ReleaseRuntimeProbe::class);
    $runtime->shouldReceive('inspect')->once()->andReturn([
        'ready' => true,
        'components' => [
            'database' => true,
            'cache' => true,
            'object_storage' => true,
            'queue_worker' => true,
            'scheduler' => true,
        ],
        'failures' => [],
    ]);
    $this->app->instance(ReleaseRuntimeProbe::class, $runtime);

    $unsignedPath = tempnam(sys_get_temp_dir(), 'chef-m8-unsigned-');
    $signedPath = tempnam(sys_get_temp_dir(), 'chef-m8-signed-');
    file_put_contents($unsignedPath, json_encode(m8LaunchManifest(), JSON_THROW_ON_ERROR));

    $this->artisan('chef:release:evidence-sign', [
        'manifest' => $unsignedPath,
        '--output' => $signedPath,
    ])->expectsOutputToContain('Signed M8 evidence written')->assertSuccessful();

    $this->artisan('chef:release:approve', ['manifest' => $signedPath])
        ->expectsOutput('Chef M8 evidence is complete. This exact deployment is eligible for the version 1 tag.')
        ->assertSuccessful();
});

it('refuses final approval when the live queue worker probe fails', function () {
    configureM8ProductionRelease();
    $this->app->detectEnvironment(fn () => 'production');

    $runtime = Mockery::mock(ReleaseRuntimeProbe::class);
    $runtime->shouldReceive('inspect')->once()->andReturn([
        'ready' => false,
        'components' => [
            'database' => true,
            'cache' => true,
            'object_storage' => true,
            'queue_worker' => false,
            'scheduler' => true,
        ],
        'failures' => ['queue_worker'],
    ]);
    $this->app->instance(ReleaseRuntimeProbe::class, $runtime);

    $manifest = m8LaunchManifest();
    $manifest['signature'] = app(M8LaunchEvidence::class)->sign($manifest);
    $path = tempnam(sys_get_temp_dir(), 'chef-m8-runtime-failure-');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->artisan('chef:release:approve', ['manifest' => $path])
        ->expectsOutputToContain('Queue worker probe failed.')
        ->assertFailed();
});

it('cannot redefine the encoded production topology through environment expectations', function () {
    config([
        'database.default' => 'sqlite',
        'queue.default' => 'sync',
        'cache.default' => 'array',
        'session.driver' => 'file',
        'broadcasting.default' => 'null',
        'filesystems.default' => 'local',
    ]);

    expect(config('chef.release'))->toMatchArray([
        'database' => 'mysql',
        'queue' => 'redis',
        'cache' => 'redis',
        'session' => 'redis',
        'broadcast' => 'reverb',
        'filesystem' => 's3',
    ]);
});
