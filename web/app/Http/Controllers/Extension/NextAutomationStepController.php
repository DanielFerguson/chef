<?php

namespace App\Http\Controllers\Extension;

use App\Actions\Automation\ClaimNextAutomationStep;
use App\Automation\RetailerOriginPolicy;
use App\Http\Controllers\Controller;
use App\Models\BrowserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NextAutomationStepController extends Controller
{
    public function __invoke(Request $request, ClaimNextAutomationStep $claim, RetailerOriginPolicy $origins): JsonResponse
    {
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $step = $claim->handle($connection);

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
