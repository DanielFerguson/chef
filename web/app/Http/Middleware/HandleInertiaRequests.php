<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'currentTeam' => $user?->currentTeam,
                'teams' => fn () => $user?->teams()
                    ->orderBy('name')
                    ->get(['teams.id', 'teams.name']) ?? [],
            ],
            'recentMealPlans' => function () use ($user) {
                $plans = $user?->currentTeam?->mealPlans()
                    ->latest('updated_at')
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get(['id', 'team_id', 'title', 'starts_on', 'ends_on', 'revision', 'planning_confirmed_at', 'shopping_approved_at']);

                if ($plans === null) {
                    return [];
                }

                return $plans->map(function ($mealPlan) use ($plans, $user): array {
                    $superseded = $mealPlan->planning_confirmed_at !== null
                        && $plans->contains(fn ($candidate): bool => $candidate->planning_confirmed_at !== null
                            && ($candidate->planning_confirmed_at->isAfter($mealPlan->planning_confirmed_at)
                                || ($candidate->planning_confirmed_at->equalTo($mealPlan->planning_confirmed_at) && $candidate->id > $mealPlan->id))
                            && $candidate->starts_on->lte($mealPlan->ends_on)
                            && $candidate->ends_on->gte($mealPlan->starts_on));

                    return [
                        'id' => $mealPlan->id,
                        'title' => $mealPlan->title,
                        'starts_on' => $mealPlan->starts_on->toDateString(),
                        'ends_on' => $mealPlan->ends_on->toDateString(),
                        'revision' => $mealPlan->revision,
                        'phase' => $superseded
                            ? 'Superseded'
                            : ($mealPlan->shopping_approved_at !== null
                            ? 'Preparing'
                            : ($mealPlan->planning_confirmed_at !== null ? 'Confirmed' : 'Draft')),
                        'can' => [
                            'update' => $user->can('update', $mealPlan),
                            'delete' => $user->can('delete', $mealPlan),
                        ],
                    ];
                });
            },
            'flash' => [
                'invitationUrl' => fn () => $request->session()->get('invitation_url'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
