<?php

namespace App\Support;

use Illuminate\Support\Arr;

class ReleaseReadiness
{
    /**
     * @return array<int, array{key: string, message: string}>
     */
    public function configurationFailures(): array
    {
        $failures = [];

        $this->expect($failures, 'app.environment', app()->environment('production'), 'APP_ENV must be production.');
        $this->expect($failures, 'app.debug', config('app.debug') === false, 'APP_DEBUG must be false.');
        $this->expect($failures, 'app.key', filled(config('app.key')), 'APP_KEY must be set.');
        $appUrl = (string) config('app.url');
        $this->expect($failures, 'app.url', $this->isPublicHttpsUrl($appUrl), 'APP_URL must be a non-placeholder public HTTPS origin.');
        $this->expect($failures, 'app.release', filled(config('app.release')), 'APP_RELEASE must identify the deployed release.');

        foreach (['database', 'queue', 'cache', 'session', 'broadcast', 'filesystem'] as $component) {
            $actual = match ($component) {
                'database' => config('database.default'),
                'queue' => config('queue.default'),
                'cache' => config('cache.default'),
                'session' => config('session.driver'),
                'broadcast' => config('broadcasting.default'),
                'filesystem' => config('filesystems.default'),
            };
            $expected = config("chef.release.{$component}");

            $this->expect(
                $failures,
                "infrastructure.{$component}",
                $actual === $expected,
                strtoupper($component).' must use '.strtoupper((string) $expected)." for the hosted release; found {$actual}.",
            );
        }

        $this->expect($failures, 'app.maintenance', config('app.maintenance.driver') === 'cache', 'Maintenance mode must use the shared cache.');
        $this->expect($failures, 'session.secure', config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $this->expect($failures, 'session.encrypt', config('session.encrypt') === true, 'SESSION_ENCRYPT must be true.');
        $this->expect($failures, 'openai.key', filled(config('ai.providers.openai.key')), 'OPENAI_API_KEY must be set.');
        $this->expect($failures, 'openai.store', config('ai.providers.openai.store') === false, 'OPENAI_STORE must remain false.');
        $this->expect(
            $failures,
            'storage.automation_screenshots',
            config('chef.storage.automation_screenshots_disk') === config('chef.release.filesystem'),
            'Automation screenshots must use the hosted private filesystem.',
        );
        $this->expect($failures, 'mail.transport', ! in_array(config('mail.default'), ['array', 'log'], true), 'MAIL_MAILER must deliver email.');
        $this->expect($failures, 'monitoring.nightwatch', filled(config('services.nightwatch.token')), 'NIGHTWATCH_TOKEN must be set.');
        $this->expect($failures, 'monitoring.request_payloads', config('nightwatch.capture_request_payload') === false, 'Nightwatch request payload capture must remain disabled.');

        $supportEmail = (string) config('chef.support.email');
        $this->expect(
            $failures,
            'support.email',
            filter_var($supportEmail, FILTER_VALIDATE_EMAIL) !== false
                && ! $this->isPlaceholderHost((string) str($supportEmail)->afterLast('@')),
            'SUPPORT_EMAIL must be a non-placeholder email address.',
        );

        foreach (['privacy_url', 'terms_url'] as $field) {
            $url = (string) Arr::get(config('chef.support'), $field);
            $this->expect(
                $failures,
                "support.{$field}",
                $this->isPublicHttpsUrl($url) && parse_url($url, PHP_URL_HOST) === parse_url($appUrl, PHP_URL_HOST),
                strtoupper($field).' must use the deployed application HTTPS host.',
            );
        }

        foreach (['operator', 'contact_address', 'processing_countries'] as $field) {
            $this->expect(
                $failures,
                "legal.{$field}",
                $this->isFinalValue((string) Arr::get(config('chef.legal'), $field)),
                strtoupper($field).' must be finalised for public policy pages.',
            );
        }

        $screenshotHours = (int) config('chef.retention.automation_screenshots_hours');
        $this->expect($failures, 'retention.screenshots', $screenshotHours >= 1 && $screenshotHours <= 24, 'Screenshot retention must be between 1 and 24 hours.');

        $conversationDays = (int) config('chef.retention.conversations_days');
        $this->expect($failures, 'retention.conversations', $conversationDays >= 30 && $conversationDays <= 730, 'Conversation retention must be between 30 and 730 days.');

        $auditDays = (int) config('chef.retention.audit_days');
        $this->expect($failures, 'retention.audit', $auditDays >= 30 && $auditDays <= 730, 'Audit retention must be between 30 and 730 days.');

        foreach (['ai_input_usd_per_million', 'ai_output_usd_per_million'] as $rate) {
            $this->expect(
                $failures,
                "quotas.{$rate}",
                (float) config("chef.quotas.{$rate}") > 0,
                strtoupper($rate).' must be set to the currently approved provider price.',
            );
        }

        $this->expect($failures, 'quotas.monthly_cost', (float) config('chef.quotas.ai_cost_usd_per_month') > 0, 'The monthly AI cost ceiling must be positive.');
        $this->expect($failures, 'quotas.monthly_tokens', (int) config('chef.quotas.ai_tokens_per_month') > 0, 'The monthly AI token ceiling must be positive.');

        return $failures;
    }

    /**
     * @param  array<int, array{key: string, message: string}>  $failures
     */
    private function expect(array &$failures, string $key, bool $passes, string $message): void
    {
        if (! $passes) {
            $failures[] = compact('key', 'message');
        }
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null
            && ! $this->isPlaceholderHost($host);
    }

    private function isPlaceholderHost(string $host): bool
    {
        return $host === ''
            || preg_match('/(^|\.)(localhost|test|invalid|example)$/i', $host) === 1
            || preg_match('/(^|\.)example\.(com|net|org)$/i', $host) === 1;
    }

    private function isFinalValue(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return $normalized !== ''
            && ! str_contains($normalized, 'replace with')
            && ! str_contains($normalized, 'approved subprocessors');
    }
}
