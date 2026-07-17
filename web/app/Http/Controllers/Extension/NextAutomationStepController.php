<?php

namespace App\Http\Controllers\Extension;

use App\Actions\Automation\ClaimNextAutomationStep;
use App\Automation\RetailerOriginPolicy;
use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NextAutomationStepController extends Controller
{
    public function __invoke(Request $request, string $run, ClaimNextAutomationStep $claim, RetailerOriginPolicy $origins): JsonResponse
    {
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $automationRun = AutomationRun::query()
            ->where('uuid', $run)
            ->where('browser_connection_id', $connection->id)
            ->firstOrFail();
        $step = $claim->handle($connection, $automationRun);

        if ($step === null) {
            return response()->json(['step' => null]);
        }

        return response()->json(['step' => [
            'id' => $step->id,
            'run_uuid' => $step->automationRun->uuid,
            'tab_id' => $step->automationRun->current_tab_id,
            'allowed_origin' => $origins->originFor($step->automationRun->retailer),
            'actions' => $step->actions,
            'validated_at' => now()->toIso8601String(),
        ]]);
    }
}
