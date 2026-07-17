<?php

namespace App\Http\Controllers\Extension;

use App\Actions\Automation\SubmitAutomationStepResult;
use App\Http\Controllers\Controller;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationStepResultController extends Controller
{
    public function __invoke(Request $request, int $step, SubmitAutomationStepResult $submit): JsonResponse
    {
        $validated = $request->validate([
            'current_url' => ['required', 'url', 'max:4000'],
            'screenshot' => ['required', 'string'],
            'result' => ['nullable', 'array'],
        ]);
        /** @var BrowserConnection $connection */
        $connection = $request->attributes->get('browser_connection');
        $automationStep = AutomationStep::query()->whereKey($step)->firstOrFail();
        $automationStep = $submit->handle(
            $automationStep,
            $connection,
            $validated['current_url'],
            $validated['screenshot'],
            $validated['result'] ?? [],
        );

        return response()->json(['step' => ['id' => $automationStep->id, 'status' => $automationStep->status->value]]);
    }
}
