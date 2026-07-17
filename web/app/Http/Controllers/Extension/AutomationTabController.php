<?php

namespace App\Http\Controllers\Extension;

use App\Actions\Automation\AttachBrowserTab;
use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationTabController extends Controller
{
    public function __invoke(Request $request, string $run, AttachBrowserTab $attach): JsonResponse
    {
        $validated = $request->validate([
            'tab_id' => ['required', 'string', 'max:100'],
            'current_url' => ['required', 'url', 'max:4000'],
            'screenshot' => ['required', 'string'],
        ]);
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $automationRun = AutomationRun::query()->where('uuid', $run)->firstOrFail();
        $automationRun->load('retailer');
        $automationRun = $attach->handle($automationRun, $connection, $validated['tab_id'], $validated['current_url'], $validated['screenshot']);

        return response()->json(['run' => ['uuid' => $automationRun->uuid, 'status' => $automationRun->status->value]]);
    }
}
