<?php

namespace App\Http\Controllers;

use App\Automation\AutomationRunView;
use App\Models\AutomationRun;
use Illuminate\Http\JsonResponse;

class AutomationRunStatusController extends Controller
{
    public function __invoke(AutomationRun $automationRun, AutomationRunView $view): JsonResponse
    {
        $this->authorize('view', $automationRun);

        return response()->json(['run' => $view->make($automationRun)]);
    }
}
