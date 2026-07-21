<?php

namespace App\Http\Controllers;

use App\Enums\PreferenceCandidateStatus;
use App\Models\MealSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($team !== null, 404, 'Create a family before opening the workspace.');
        $this->authorize('view', $team);

        $today = Date::now($team->timezone)->toDateString();
        $slotQuery = MealSlot::query()
            ->where('team_id', $team->id)
            ->whereDate('date', $today)
            ->with([
                'participants:id,name',
                'plannedMeal.recipeVersion:id,recipe_id,title,summary,prep_minutes,cook_minutes',
                'plannedMeal.recipeVersion.preparationNotices',
                'plannedMeal.outcome.feedback',
            ])
            ->orderBy('position');
        $slots = $slotQuery->get();
        $showingNext = false;

        if ($slots->whereNotNull('plannedMeal')->isEmpty()) {
            $nextDate = MealSlot::query()
                ->where('team_id', $team->id)
                ->whereDate('date', '>', $today)
                ->whereHas('plannedMeal')
                ->min('date');

            if ($nextDate !== null) {
                $nextDate = Date::parse($nextDate, $team->timezone)->toDateString();
                $slots = MealSlot::query()
                    ->where('team_id', $team->id)
                    ->whereDate('date', $nextDate)
                    ->with([
                        'participants:id,name',
                        'plannedMeal.recipeVersion:id,recipe_id,title,summary,prep_minutes,cook_minutes',
                        'plannedMeal.recipeVersion.preparationNotices',
                        'plannedMeal.outcome.feedback',
                    ])
                    ->orderBy('position')
                    ->get();
                $showingNext = true;
            }
        }

        $people = $team->people()
            ->with('userLink.user:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn ($person) => [
                'id' => $person->id,
                'name' => $person->name,
                'has_account' => $person->userLink !== null,
                'email' => $person->userLink?->user?->email,
            ]);
        $preferenceCandidates = [];

        foreach ($team->preferenceCandidates()
            ->where('status', PreferenceCandidateStatus::Pending)
            ->with('person:id,name')
            ->latest('updated_at')
            ->get() as $candidate) {
            $preferenceCandidates[] = [
                'id' => $candidate->id,
                'person' => $candidate->person,
                'subject' => $candidate->subject,
                'sentiment' => $candidate->sentiment->value,
                'evidence_count' => $candidate->evidence_count,
                'confidence' => $candidate->confidence,
                'status' => $candidate->status->value,
            ];
        }

        return Inertia::render('dashboard', [
            'household' => [
                'id' => $team->id,
                'name' => $team->name,
                'timezone' => $team->timezone,
                'people' => $people,
            ],
            'today' => [
                'date' => $today,
                'showing_next' => $showingNext,
                'meals' => $this->serializeMeals($slots),
            ],
            'preferenceCandidates' => $preferenceCandidates,
        ]);
    }

    /**
     * @param  Collection<int, MealSlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function serializeMeals(Collection $slots): array
    {
        $meals = [];

        foreach ($slots as $slot) {
            $meal = $slot->plannedMeal;

            if ($meal === null) {
                continue;
            }

            $recipe = $meal->recipeVersion;
            $participants = [];

            foreach ($slot->participants as $person) {
                $participants[] = ['id' => $person->id, 'name' => $person->name];
            }

            $preparationNotices = [];

            if ($recipe !== null) {
                foreach ($recipe->preparationNotices as $notice) {
                    $preparationNotices[] = [
                        'id' => $notice->id,
                        'instruction' => $notice->instruction,
                        'lead_minutes' => $notice->lead_minutes,
                    ];
                }
            }

            $meals[] = [
                'id' => $meal->id,
                'title' => $meal->title,
                'type' => $meal->type->value,
                'date' => $slot->date->toDateString(),
                'kind' => $slot->kind->value,
                'label' => $slot->label,
                'participants' => $participants,
                'recipe' => $recipe === null ? null : [
                    'title' => $recipe->title,
                    'summary' => $recipe->summary,
                    'total_minutes' => ($recipe->prep_minutes ?? 0) + ($recipe->cook_minutes ?? 0),
                    'preparation_notices' => $preparationNotices,
                ],
                'outcome' => $meal->outcome === null ? null : [
                    'id' => $meal->outcome->id,
                    'status' => $meal->outcome->status?->value,
                    'started_at' => $meal->outcome->started_at?->toIso8601String(),
                    'completed_at' => $meal->outcome->completed_at?->toIso8601String(),
                    'feedback_count' => $meal->outcome->feedback->count(),
                ],
            ];
        }

        return $meals;
    }
}
