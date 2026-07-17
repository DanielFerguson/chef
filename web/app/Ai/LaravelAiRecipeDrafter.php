<?php

namespace App\Ai;

use App\Ai\Agents\RecipeDraftingAgent;
use App\Ai\Contracts\RecipeDrafter;
use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;
use App\Models\Team;
use App\Support\OperationalMetrics;
use App\Support\UsageGuard;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiRecipeDrafter implements RecipeDrafter
{
    public function __construct(
        private readonly OperationalMetrics $metrics,
        private readonly UsageGuard $usageGuard,
    ) {}

    public function draft(RecipeDraftRequest $request): RecipeDraft
    {
        $team = Team::query()->findOrFail($request->teamId);
        $this->usageGuard->assertAiAllowed($team);
        $startedAt = hrtime(true);
        $response = (new RecipeDraftingAgent($request))->prompt('Prepare the structured recipe now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Recipe drafting did not return structured output.');
        }

        $this->metrics->recordAi(
            $team,
            null,
            'recipe_draft',
            'completed',
            $response->invocationId,
            $response->meta->provider,
            $response->meta->model,
            $response->usage,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'planned_meal',
            $request->plannedMealId,
        );

        return RecipeDraft::fromArray($response->structured);
    }
}
