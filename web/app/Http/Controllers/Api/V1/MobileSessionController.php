<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('currentTeam.people');
        $team = $user->currentTeam;

        abort_unless($team !== null, 404, 'Create a family before opening Chef.');
        $this->authorize('view', $team);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'household' => [
                'id' => $team->id,
                'name' => $team->name,
                'timezone' => $team->timezone,
                'people' => $team->people->map->only(['id', 'name'])->values(),
            ],
            'plans' => $team->mealPlans()
                ->latest('starts_on')
                ->get(['id', 'title', 'starts_on', 'ends_on', 'revision', 'planning_confirmed_at']),
            'notifications' => $this->notifications($request),
        ]);
    }

    /** @return array{unread_count: int, items: list<array<string, mixed>>} */
    private function notifications(Request $request): array
    {
        $notificationQuery = $request->user()
            ->notifications()
            ->where('data->team_id', $request->user()->current_team_id);
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
            'items' => array_values($notifications
                ->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'],
                    'message' => $notification->data['message'],
                    'status' => $notification->data['status'],
                    'basket_run_id' => $notification->data['basket_run_id'],
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
