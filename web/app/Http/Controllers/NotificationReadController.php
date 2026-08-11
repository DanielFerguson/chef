<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationReadController extends Controller
{
    public function __invoke(Request $request, string $notification): JsonResponse
    {
        $databaseNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->where('data->team_id', $request->user()->current_team_id)
            ->firstOrFail();
        $databaseNotification->markAsRead();

        return response()->json(['read_at' => $databaseNotification->read_at?->toIso8601String()]);
    }
}
