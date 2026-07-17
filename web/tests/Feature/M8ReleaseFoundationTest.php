<?php

namespace Tests\Feature;

use App\Support\ReleaseReadiness;
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
