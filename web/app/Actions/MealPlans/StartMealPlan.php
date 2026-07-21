<?php

namespace App\Actions\MealPlans;

use App\Enums\MessageRole;
use App\Models\MealPlan;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartMealPlan
{
    public function handle(
        Team $team,
        User $user,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?string $title = null,
    ): MealPlan {
        if (! $user->memberships()->whereBelongsTo($team)->exists()) {
            throw new AuthorizationException('You do not belong to this family.');
        }

        if ($endsOn->isBefore($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'The plan end date must be on or after its start date.',
            ]);
        }

        return DB::transaction(function () use ($team, $user, $startsOn, $endsOn, $title): MealPlan {
            $mealPlan = $team->mealPlans()->create([
                'created_by_user_id' => $user->id,
                'title' => $title ?? MealPlanDateTitle::format($startsOn, $endsOn),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);

            $conversation = $mealPlan->conversations()->create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'title' => $mealPlan->title,
            ]);

            $conversation->messages()->create([
                'team_id' => $team->id,
                'role' => MessageRole::Assistant,
                'content' => "Let's plan {$mealPlan->title}. Who are we feeding, and which meals should we cover?",
            ]);

            return $mealPlan->load('conversations.messages');
        });
    }
}
