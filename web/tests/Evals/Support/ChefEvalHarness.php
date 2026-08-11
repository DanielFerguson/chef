<?php

namespace Tests\Evals\Support;

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\MessageRole;
use App\Models\User;
use Closure;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

final class ChefEvalHarness
{
    /**
     * Build an isolated Chef fixture only when Pest is running in eval mode.
     *
     * @return Closure(string): string
     */
    public static function task(): Closure
    {
        return function (string $input): string {
            $user = User::factory()->create(['name' => 'Alex']);
            $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
            $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
            $conversation = $plan->conversations()->firstOrFail();
            $currentMessage = $conversation->messages()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'content' => $input,
            ]);

            $response = (new ChefAgent(
                $conversation,
                $currentMessage->id,
                $user,
                $currentMessage,
            ))->prompt($input);

            return json_encode([
                'answer' => $response->text,
                'tool_calls' => $response->toolCalls
                    ->map(static fn (ToolCall $toolCall): array => [
                        'name' => $toolCall->name,
                        'arguments' => $toolCall->arguments,
                    ])
                    ->values()
                    ->all(),
                'tool_results' => $response->toolResults
                    ->map(static fn (ToolResult $toolResult): array => [
                        'name' => $toolResult->name,
                        'result' => $toolResult->result,
                    ])
                    ->values()
                    ->all(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        };
    }

    /** @return list<string> */
    public static function toolNames(string $output): array
    {
        /** @var array{tool_calls?: list<array{name?: mixed}>} $payload */
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_map(
            static fn (array $toolCall): ?string => is_string($toolCall['name'] ?? null)
                ? $toolCall['name']
                : null,
            $payload['tool_calls'] ?? [],
        )));
    }
}
