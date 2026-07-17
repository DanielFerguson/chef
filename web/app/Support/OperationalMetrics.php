<?php

namespace App\Support;

use App\Models\ConsentRecord;
use App\Models\OperationalEvent;
use App\Models\Team;
use App\Models\User;
use Laravel\Ai\Responses\Data\Usage;

class OperationalMetrics
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function recordAi(
        Team $team,
        ?User $user,
        string $name,
        string $status,
        ?string $invocationId,
        ?string $provider,
        ?string $model,
        ?Usage $usage,
        int $durationMs,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
    ): OperationalEvent {
        $promptTokens = $usage === null ? 0 : $usage->promptTokens;
        $completionTokens = $usage === null ? 0 : $usage->completionTokens;
        $cacheReadTokens = $usage === null ? 0 : $usage->cacheReadInputTokens;
        $reasoningTokens = $usage === null ? 0 : $usage->reasoningTokens;

        return $this->record([
            'team_id' => $team->id,
            'user_id' => $user?->id,
            'category' => 'ai',
            'name' => $name,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'invocation_id' => $invocationId,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'reasoning_tokens' => $reasoningTokens,
            'estimated_cost_microusd' => $this->estimateCost($promptTokens, $completionTokens, $cacheReadTokens),
            'duration_ms' => $durationMs,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, scalar|null> $metadata */
    public function recordAutomation(Team $team, ?User $user, string $name, string $status, ?int $runId, array $metadata = []): OperationalEvent
    {
        return $this->record([
            'team_id' => $team->id,
            'user_id' => $user?->id,
            'category' => 'automation',
            'name' => $name,
            'status' => $status,
            'subject_type' => 'automation_run',
            'subject_id' => $runId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Product analytics is optional and deliberately content-free.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function recordProduct(Team $team, User $user, string $name, array $metadata = []): ?OperationalEvent
    {
        $latest = ConsentRecord::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('kind', 'product_analytics')
            ->latest('occurred_at')
            ->first();

        if ($latest?->status !== 'granted') {
            return null;
        }

        return $this->record([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'category' => 'product',
            'name' => $name,
            'status' => 'completed',
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function record(array $attributes): OperationalEvent
    {
        return OperationalEvent::query()->create([
            ...$attributes,
            'occurred_at' => now(),
        ]);
    }

    private function estimateCost(int $promptTokens, int $completionTokens, int $cacheReadTokens): int
    {
        $inputRate = (float) config('chef.quotas.ai_input_usd_per_million', 0);
        $outputRate = (float) config('chef.quotas.ai_output_usd_per_million', 0);
        $cacheRate = (float) config('chef.quotas.ai_cache_read_usd_per_million', 0);

        return (int) ceil(
            ($promptTokens * $inputRate)
            + ($completionTokens * $outputRate)
            + ($cacheReadTokens * $cacheRate),
        );
    }
}
