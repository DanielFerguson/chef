<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\RecipeDrafter;
use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;

class DeterministicRecipeDrafter implements RecipeDrafter
{
    public function draft(RecipeDraftRequest $request): RecipeDraft
    {
        return new RecipeDraft(
            title: $request->title,
            summary: $request->summary ?? 'A practical Chef-prepared recipe.',
            servings: $request->servings,
            prepMinutes: 10,
            cookMinutes: max(10, ($request->estimatedMinutes ?? 30) - 10),
            ingredients: [[
                'name' => $request->title.' ingredients',
                'quantity' => 1.0,
                'unit' => 'batch',
                'preparation' => null,
                'optional' => false,
            ]],
            steps: [[
                'instruction' => 'Prepare and cook '.$request->title.'.',
                'timer_minutes' => null,
            ]],
            equipment: ['Pan'],
            notices: [],
        );
    }
}
