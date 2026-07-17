<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;
use Throwable;

class M8LaunchEvidence
{
    public const SCHEMA = 'chef.m8-release-evidence.v1';

    /** @var array<string, int> Maximum evidence age in days. */
    private const GATES = [
        'staging_probe' => 14,
        'production_probe' => 2,
        'backup_restore' => 30,
        'privacy_legal' => 90,
        'data_controls' => 30,
        'woolworths_handoff' => 30,
        'coles_handoff' => 30,
        'realtime_microphone' => 30,
        'private_beta' => 90,
        'production_replay' => 2,
    ];

    /** @param array<string, mixed> $manifest
     * @return array<int, array{key: string, message: string}>
     */
    public function payloadFailures(array $manifest): array
    {
        $failures = [];
        $approvedAt = $this->date($manifest['approved_at'] ?? null);

        $this->expect(
            $failures,
            'fields',
            $this->hasOnlyKeys($manifest, ['schema', 'release', 'deployment_origin', 'approved_by', 'approved_at', 'gates'], ['signature']),
            'Evidence may contain only the content-free M8 schema fields.',
        );
        $this->expect($failures, 'schema', ($manifest['schema'] ?? null) === self::SCHEMA, 'Evidence schema must be '.self::SCHEMA.'.');
        $this->expect($failures, 'release', is_string($manifest['release'] ?? null) && hash_equals((string) config('app.release'), $manifest['release']), 'Evidence release must match APP_RELEASE exactly.');
        $this->expect($failures, 'deployment_origin', is_string($manifest['deployment_origin'] ?? null) && rtrim($manifest['deployment_origin'], '/') === rtrim((string) config('app.url'), '/'), 'Evidence deployment origin must match APP_URL exactly.');
        $this->expect($failures, 'approved_by', $this->isLabel($manifest['approved_by'] ?? null), 'An accountable release approver of at most 120 characters is required.');
        $this->expect(
            $failures,
            'approved_at',
            $approvedAt !== null
                && ! $approvedAt->isFuture()
                && $approvedAt->greaterThanOrEqualTo(CarbonImmutable::now()->subDay()),
            'APPROVED_AT must be a strict timestamp from the last 24 hours and cannot be in the future.',
        );

        $gates = is_array($manifest['gates'] ?? null) ? $manifest['gates'] : [];
        $this->expect($failures, 'gates', $this->hasOnlyKeys($gates, array_keys(self::GATES)), 'Evidence must contain exactly the required M8 gates.');

        foreach (self::GATES as $name => $maximumAgeDays) {
            $gate = is_array($gates[$name] ?? null) ? $gates[$name] : [];
            $prefix = "gates.{$name}";
            $completedAt = $this->date($gate['completed_at'] ?? null);

            $this->expect($failures, "{$prefix}.fields", $this->hasOnlyKeys($gate, ['result', 'completed_at', 'operator', 'evidence_url', 'evidence_sha256', 'metrics']), "{$name} may contain only content-free gate fields.");
            $this->expect($failures, "{$prefix}.result", ($gate['result'] ?? null) === 'passed', "{$name} must have a passed result; waivers do not satisfy M8.");
            $this->expect($failures, "{$prefix}.operator", $this->isLabel($gate['operator'] ?? null), "{$name} must identify its operator in at most 120 characters.");
            $this->expect($failures, "{$prefix}.evidence_url", $this->isEvidenceUrl($gate['evidence_url'] ?? null), "{$name} must link to HTTPS evidence.");
            $this->expect($failures, "{$prefix}.evidence_sha256", is_string($gate['evidence_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $gate['evidence_sha256']) === 1, "{$name} must record a lowercase SHA-256 evidence digest.");
            $this->expect(
                $failures,
                "{$prefix}.completed_at",
                $completedAt !== null
                    && $approvedAt !== null
                    && ! $completedAt->isAfter($approvedAt)
                    && $completedAt->greaterThanOrEqualTo($approvedAt->subDays($maximumAgeDays)),
                "{$name} evidence must be completed before approval and no more than {$maximumAgeDays} days old.",
            );

            $metrics = is_array($gate['metrics'] ?? null) ? $gate['metrics'] : [];
            $this->expect($failures, "{$prefix}.metrics", $this->hasOnlyKeys($metrics, $this->metricKeys($name)), "{$name} metrics do not match the M8 evidence schema.");
            $this->validateMetrics($failures, $name, $metrics);
        }

        return $failures;
    }

    /** @param array<string, mixed> $manifest
     * @return array<int, array{key: string, message: string}>
     */
    public function failures(array $manifest): array
    {
        $failures = $this->payloadFailures($manifest);
        $key = (string) config('chef.release.evidence_key');
        $signature = $manifest['signature'] ?? null;

        $this->expect($failures, 'signature.key', strlen($key) >= 32, 'RELEASE_EVIDENCE_KEY must contain at least 32 characters.');
        $this->expect(
            $failures,
            'signature',
            strlen($key) >= 32
                && is_string($signature)
                && preg_match('/\A[a-f0-9]{64}\z/', $signature) === 1
                && hash_equals($this->sign($manifest), $signature),
            'Evidence signature is missing or invalid.',
        );

        return $failures;
    }

    /** @param array<string, mixed> $manifest */
    public function sign(array $manifest): string
    {
        $key = (string) config('chef.release.evidence_key');

        if (strlen($key) < 32) {
            throw new RuntimeException('RELEASE_EVIDENCE_KEY must contain at least 32 characters.');
        }

        unset($manifest['signature']);

        try {
            $payload = json_encode($this->canonicalize($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Release evidence could not be encoded.', previous: $exception);
        }

        return hash_hmac('sha256', $payload, $key);
    }

    /** @param array<int, array{key: string, message: string}> $failures
     * @param  array<string, mixed>  $metrics
     */
    private function validateMetrics(array &$failures, string $gate, array $metrics): void
    {
        $truths = array_values(array_filter($this->metricKeys($gate), fn (string $metric): bool => ! in_array($metric, [
            'attempts', 'households', 'success_rate', 'sessions', 'mobile_households',
            'first_plan_success_rate', 'shopping_success_rate', 'cooking_success_rate',
            'first_plan_median_minutes', 'critical_open', 'high_open',
            'recovery_point_hours', 'recovery_time_minutes', 'automation_failure_rate',
            'monthly_ai_cost_per_active_family_usd', 'monthly_ai_cost_ceiling_usd',
        ], true)));

        foreach ($truths as $metric) {
            $this->expect($failures, "gates.{$gate}.metrics.{$metric}", ($metrics[$metric] ?? null) === true, "{$gate}.{$metric} must be proven true.");
        }

        if (in_array($gate, ['woolworths_handoff', 'coles_handoff'], true)) {
            $this->countMinimum($failures, $gate, $metrics, 'attempts', 3);
            $this->countMinimum($failures, $gate, $metrics, 'households', 2);
            $this->rate($failures, $gate, $metrics, 'success_rate', 0.8);
        }

        if ($gate === 'realtime_microphone') {
            $this->countMinimum($failures, $gate, $metrics, 'sessions', 5);
            $this->rate($failures, $gate, $metrics, 'success_rate', 0.8);
        }

        if ($gate === 'private_beta') {
            $this->countMinimum($failures, $gate, $metrics, 'households', 3);
            $this->countMinimum($failures, $gate, $metrics, 'mobile_households', 2);
            $this->rate($failures, $gate, $metrics, 'first_plan_success_rate', 0.8);
            $this->rate($failures, $gate, $metrics, 'shopping_success_rate', 0.8);
            $this->rate($failures, $gate, $metrics, 'cooking_success_rate', 0.8);
            $median = $metrics['first_plan_median_minutes'] ?? null;
            $this->expect($failures, "gates.{$gate}.metrics.first_plan_median_minutes", (is_int($median) || is_float($median)) && (float) $median > 0 && (float) $median <= 15, 'private_beta.first_plan_median_minutes must be greater than zero and no more than 15.');
            $this->expect($failures, "gates.{$gate}.metrics.critical_open", ($metrics['critical_open'] ?? null) === 0, 'private_beta.critical_open must be zero.');
            $this->expect($failures, "gates.{$gate}.metrics.high_open", ($metrics['high_open'] ?? null) === 0, 'private_beta.high_open must be zero.');
        }

        if ($gate === 'backup_restore') {
            $this->maximum($failures, $gate, $metrics, 'recovery_point_hours', 24, positive: true);
            $this->maximum($failures, $gate, $metrics, 'recovery_time_minutes', 240, positive: true);
        }

        if ($gate === 'production_replay') {
            $this->maximum($failures, $gate, $metrics, 'automation_failure_rate', 0.2);
            $cost = $metrics['monthly_ai_cost_per_active_family_usd'] ?? null;
            $ceiling = $metrics['monthly_ai_cost_ceiling_usd'] ?? null;
            $this->expect(
                $failures,
                "gates.{$gate}.metrics.monthly_ai_cost_per_active_family_usd",
                (is_int($cost) || is_float($cost))
                    && (is_int($ceiling) || is_float($ceiling))
                    && (float) $cost >= 0
                    && (float) $ceiling > 0
                    && (float) $cost <= (float) $ceiling,
                'production_replay monthly AI cost per active family must be non-negative and no greater than the positive configured ceiling.',
            );
        }
    }

    /** @return array<int, string> */
    private function metricKeys(string $gate): array
    {
        return match ($gate) {
            'staging_probe', 'production_probe' => [
                'release_check', 'database', 'cache', 'object_storage', 'queue_worker',
                'scheduler', 'broadcast_tenancy', 'nightwatch_web', 'nightwatch_worker',
                'mail_delivery',
            ],
            'backup_restore' => ['restore_completed', 'schema_current', 'row_counts_reconciled', 'private_files_verified', 'source_untouched', 'recovery_point_hours', 'recovery_time_minutes'],
            'privacy_legal' => ['privacy_approved', 'terms_approved', 'operator_final', 'processing_countries_final', 'support_route_tested'],
            'data_controls' => ['tenant_isolation', 'team_deletion', 'account_deletion', 'account_export', 'conversation_expiry', 'screenshot_expiry', 'audit_expiry'],
            'production_replay' => ['previous_milestones_passed', 'mysql_ci_passed', 'full_suite_passed', 'browser_suite_passed', 'dependency_audits_clear', 'security_review_clear', 'cost_ceiling_configured', 'automation_failure_rate_measured', 'automation_failure_rate', 'monthly_ai_cost_per_active_family_usd', 'monthly_ai_cost_ceiling_usd'],
            'woolworths_handoff', 'coles_handoff' => ['cart_persistence', 'selected_store', 'pause', 'takeover', 'pre_checkout_stop', 'attempts', 'households', 'success_rate'],
            'realtime_microphone' => ['permission_disclosure', 'revocation', 'interruption', 'reconnect', 'mute', 'transcript', 'typed_fallback', 'sessions', 'success_rate'],
            'private_beta' => ['medium_issues_decided', 'support_sla_met', 'households', 'mobile_households', 'first_plan_success_rate', 'shopping_success_rate', 'cooking_success_rate', 'first_plan_median_minutes', 'critical_open', 'high_open'],
            default => [],
        };
    }

    /** @param array<int, array{key: string, message: string}> $failures
     * @param  array<string, mixed>  $metrics
     */
    private function countMinimum(array &$failures, string $gate, array $metrics, string $metric, int $minimum): void
    {
        $value = $metrics[$metric] ?? null;
        $this->expect($failures, "gates.{$gate}.metrics.{$metric}", is_int($value) && $value >= $minimum, "{$gate}.{$metric} must be an integer of at least {$minimum}.");
    }

    /** @param array<int, array{key: string, message: string}> $failures
     * @param  array<string, mixed>  $metrics
     */
    private function rate(array &$failures, string $gate, array $metrics, string $metric, float $minimum): void
    {
        $value = $metrics[$metric] ?? null;
        $this->expect(
            $failures,
            "gates.{$gate}.metrics.{$metric}",
            (is_int($value) || is_float($value)) && (float) $value >= $minimum && (float) $value <= 1,
            "{$gate}.{$metric} must be between {$minimum} and 1.",
        );
    }

    /** @param array<int, array{key: string, message: string}> $failures
     * @param  array<string, mixed>  $metrics
     */
    private function maximum(array &$failures, string $gate, array $metrics, string $metric, int|float $maximum, bool $positive = false): void
    {
        $value = $metrics[$metric] ?? null;
        $this->expect(
            $failures,
            "gates.{$gate}.metrics.{$metric}",
            (is_int($value) || is_float($value))
                && (float) $value <= $maximum
                && (! $positive || (float) $value > 0)
                && ($positive || (float) $value >= 0),
            "{$gate}.{$metric} must be ".($positive ? 'greater than zero and ' : 'non-negative and ')."no more than {$maximum}.",
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat(DATE_ATOM, $value);

            return $date !== null && $date->format(DATE_ATOM) === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isEvidenceUrl(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $host = (string) parse_url($value, PHP_URL_HOST);

        return parse_url($value, PHP_URL_SCHEME) === 'https'
            && $host !== ''
            && preg_match('/(^|\.)(localhost|test|invalid|example)$/i', $host) !== 1
            && preg_match('/(^|\.)example\.(com|net|org)$/i', $host) !== 1
            && parse_url($value, PHP_URL_USER) === null
            && parse_url($value, PHP_URL_PASS) === null
            && parse_url($value, PHP_URL_QUERY) === null
            && parse_url($value, PHP_URL_FRAGMENT) === null;
    }

    private function isLabel(mixed $value): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && strlen($value) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** @param array<string, mixed> $value
     * @param  array<int, string>  $required
     * @param  array<int, string>  $optional
     */
    private function hasOnlyKeys(array $value, array $required, array $optional = []): bool
    {
        $keys = array_keys($value);

        return array_diff($required, $keys) === []
            && array_diff($keys, [...$required, ...$optional]) === [];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @param array<int, array{key: string, message: string}> $failures */
    private function expect(array &$failures, string $key, bool $passes, string $message): void
    {
        if (! $passes) {
            $failures[] = compact('key', 'message');
        }
    }
}
