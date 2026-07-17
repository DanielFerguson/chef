<?php

namespace App\Http\Controllers\Extension;

use App\Enums\AutomationRunStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AwaitingAutomationRunController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $runs = AutomationRun::query()
            ->where('browser_connection_id', $connection->id)
            ->where('status', AutomationRunStatus::AwaitingBrowser)
            ->where('expires_at', '>', now())
            ->with('retailer:id,name,slug')
            ->oldest()
            ->get()
            ->map(fn (AutomationRun $run) => [
                'uuid' => $run->uuid,
                'retailer' => $run->retailer,
                'shopping_list_revision' => $run->shopping_list_revision,
                'expires_at' => $run->expires_at->toIso8601String(),
            ]);

        $connection->update(['last_seen_at' => now()]);

        return response()->json(['runs' => $runs]);
    }
}
