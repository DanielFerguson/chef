<?php

namespace App\Http\Controllers\Extension;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationStepControlController extends Controller
{
    public function __invoke(Request $request, int $step): JsonResponse
    {
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $automationStep = AutomationStep::query()->with('automationRun')->findOrFail($step);
        $run = $automationStep->automationRun;

        if ($run->browser_connection_id !== $connection->id || $run->team_id !== $connection->team_id) {
            throw new AuthorizationException('This browser cannot inspect the automation step.');
        }

        return response()->json([
            'continue' => $automationStep->status === AutomationStepStatus::Executing
                && $run->status === AutomationRunStatus::Executing,
            'run_status' => $run->status->value,
            'step_status' => $automationStep->status->value,
        ]);
    }
}
