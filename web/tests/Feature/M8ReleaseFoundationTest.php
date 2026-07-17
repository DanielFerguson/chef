<?php

namespace Tests\Feature;

use App\Jobs\RecordReleaseQueueProbe;
use App\Support\ReleaseReadiness;
use App\Support\ReleaseRuntimeProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class M8ReleaseFoundationTest extends TestCase
{
    public function test_readiness_endpoint_proves_database_and_cache_without_exposing_details(): void
    {
        config(['app.release' => 'test-release']);

        $this->get(route('ready'))
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'release' => 'test-release',
            ]);
    }

    public function test_release_check_rejects_local_configuration(): void
    {
        $this->artisan('chef:release:check')
            ->expectsOutputToContain('Chef release configuration is not ready.')
            ->assertFailed();
    }

    public function test_runtime_probe_proves_database_cache_object_storage_queue_and_scheduler(): void
    {
        config([
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
            'chef.release.queue_probe_timeout_seconds' => 1,
            'chef.release.scheduler_heartbeat_max_age_seconds' => 180,
        ]);
        Storage::fake('local');
        Cache::put('chef:release:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));

        $result = app(ReleaseRuntimeProbe::class)->inspect();

        $this->assertTrue($result['ready']);
        $this->assertSame([
            'database' => true,
            'cache' => true,
            'object_storage' => true,
            'queue_worker' => true,
            'scheduler' => true,
        ], $result['components']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_runtime_probe_rejects_a_stale_scheduler_heartbeat(): void
    {
        config([
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
            'chef.release.queue_probe_timeout_seconds' => 1,
            'chef.release.scheduler_heartbeat_max_age_seconds' => 180,
        ]);
        Storage::fake('local');
        Cache::put('chef:release:scheduler-heartbeat', now()->subMinutes(4)->toIso8601String(), now()->addMinutes(10));

        $result = app(ReleaseRuntimeProbe::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['components']['scheduler']);
        $this->assertContains('scheduler', $result['failures']);
    }

    public function test_runtime_probe_rejects_a_queue_that_does_not_process_the_probe(): void
    {
        Queue::fake();
        config([
            'filesystems.default' => 'local',
            'chef.release.queue_probe_timeout_seconds' => 1,
            'chef.release.scheduler_heartbeat_max_age_seconds' => 180,
        ]);
        Storage::fake('local');
        Cache::put('chef:release:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));

        $result = app(ReleaseRuntimeProbe::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['components']['queue_worker']);
        $this->assertContains('queue_worker', $result['failures']);
    }

    public function test_runtime_probe_contains_an_unavailable_cache_store(): void
    {
        config([
            'cache.default' => 'missing-store',
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
            'chef.release.queue_probe_timeout_seconds' => 1,
        ]);
        Storage::fake('local');

        $result = app(ReleaseRuntimeProbe::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['components']['cache']);
        $this->assertFalse($result['components']['queue_worker']);
        $this->assertFalse($result['components']['scheduler']);
        $this->assertContains('cache', $result['failures']);
    }

    public function test_runtime_probe_contains_an_unavailable_object_storage_disk(): void
    {
        config([
            'filesystems.default' => 'missing-disk',
            'queue.default' => 'sync',
            'chef.release.queue_probe_timeout_seconds' => 1,
        ]);
        Cache::put('chef:release:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));

        $result = app(ReleaseRuntimeProbe::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['components']['object_storage']);
        $this->assertContains('object_storage', $result['failures']);
    }

    public function test_scheduler_heartbeat_dispatches_a_real_queue_job(): void
    {
        Queue::fake();

        $this->artisan('chef:release:heartbeat')
            ->expectsOutput('Chef release heartbeats dispatched.')
            ->assertSuccessful();

        $this->assertIsString(Cache::get('chef:release:scheduler-heartbeat'));
        Queue::assertPushed(
            RecordReleaseQueueProbe::class,
            fn (RecordReleaseQueueProbe $job): bool => $job->token === null,
        );
    }

    public function test_release_heartbeat_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('chef:release:heartbeat')
            ->assertSuccessful();
    }

    public function test_release_check_reports_runtime_component_failures(): void
    {
        $runtime = \Mockery::mock(ReleaseRuntimeProbe::class);
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

        $this->artisan('chef:release:check', ['--probe' => true])
            ->expectsOutputToContain('Queue worker probe failed.')
            ->assertFailed();
    }

    public function test_release_configuration_contract_can_be_satisfied(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:test-key',
            'app.url' => 'https://app.cheffamily.com',
            'app.release' => 'v1.0.0',
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
            'chef.legal.operator' => 'Chef Test Pty Ltd',
            'chef.legal.contact_address' => '1 Test Street, Melbourne VIC 3000',
            'chef.legal.processing_countries' => 'Australia and the United States',
            'chef.retention.automation_screenshots_hours' => 24,
            'chef.retention.conversations_days' => 730,
            'chef.retention.audit_days' => 730,
            'chef.quotas.ai_input_usd_per_million' => 1.25,
            'chef.quotas.ai_output_usd_per_million' => 10,
            'chef.quotas.ai_cost_usd_per_month' => 25,
            'chef.quotas.ai_tokens_per_month' => 2_000_000,
        ]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame([], app(ReleaseReadiness::class)->configurationFailures());
    }

    public function test_release_configuration_rejects_unbounded_sensitive_retention(): void
    {
        config([
            'chef.retention.automation_screenshots_hours' => 25,
            'chef.retention.conversations_days' => 731,
            'chef.retention.audit_days' => 731,
        ]);

        $keys = collect(app(ReleaseReadiness::class)->configurationFailures())->pluck('key');

        $this->assertTrue($keys->contains('retention.screenshots'));
        $this->assertTrue($keys->contains('retention.conversations'));
        $this->assertTrue($keys->contains('retention.audit'));
    }

    public function test_release_configuration_rejects_placeholder_public_details(): void
    {
        config([
            'app.url' => 'https://chef.example.com',
            'chef.support.email' => 'support@chef.example.com',
            'chef.support.privacy_url' => 'https://chef.example.com/privacy',
            'chef.support.terms_url' => 'https://chef.example.com/terms',
            'chef.legal.operator' => 'Replace with operating legal entity',
            'chef.legal.contact_address' => 'Replace with postal contact address',
            'chef.legal.processing_countries' => 'Australia and the countries listed by approved subprocessors',
        ]);

        $keys = collect(app(ReleaseReadiness::class)->configurationFailures())->pluck('key');

        $this->assertTrue($keys->contains('app.url'));
        $this->assertTrue($keys->contains('support.email'));
        $this->assertTrue($keys->contains('support.privacy_url'));
        $this->assertTrue($keys->contains('support.terms_url'));
        $this->assertTrue($keys->contains('legal.operator'));
        $this->assertTrue($keys->contains('legal.contact_address'));
        $this->assertTrue($keys->contains('legal.processing_countries'));
    }
}
