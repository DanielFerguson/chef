<?php

namespace App\Http\Middleware;

use App\Actions\Teams\PendingTeamInvitation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly PendingTeamInvitation $pendingInvitation) {}

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
            'pendingInvitation' => fn () => $this->pendingInvitation->shared($request),
            'notifications' => function () use ($user) {
                if ($user === null) {
                    return ['unread_count' => 0, 'items' => []];
                }

                $teamId = $user->current_team_id;
                if ($teamId === null) {
                    return ['unread_count' => 0, 'items' => []];
                }

                $notificationQuery = $user->notifications()
                    ->where('data->team_id', $teamId);
                $unreadCount = (clone $notificationQuery)
                    ->whereNull('read_at')
                    ->count();
                $notifications = $notificationQuery
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->values();

                return [
                    'unread_count' => $unreadCount,
                    'items' => $notifications->map(fn ($notification): array => [
                        'id' => $notification->id,
                        'title' => $notification->data['title'],
                        'message' => $notification->data['message'],
                        'status' => $notification->data['status'],
                        'basket_run_id' => $notification->data['basket_run_id'],
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ])->all(),
                ];
            },
            'recentMealPlans' => function () use ($user) {
                $plans = $user?->currentTeam?->mealPlans()
                    ->latest('updated_at')
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get(['id', 'team_id', 'title', 'starts_on', 'ends_on', 'revision', 'planning_confirmed_at', 'recipe_generation_status']);

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
                            : (in_array($mealPlan->recipe_generation_status?->value, ['pending', 'processing'], true)
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
