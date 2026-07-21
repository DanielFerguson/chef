<?php

namespace App\Http\Controllers;

use App\Actions\Automation\FinishAutomationTakeover;
use App\Actions\Automation\StartAutomationTakeover;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Models\AutomationRun;
use App\Models\BrowserSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationTakeoverController extends Controller
{
    public function store(
        Request $request,
        AutomationRun $automationRun,
        StartAutomationTakeover $takeover,
    ): RedirectResponse {
        $session = $takeover->handle($automationRun, $request->user());

        return to_route('browser-sessions.takeover.show', $session);
    }

    public function show(BrowserSession $browserSession): Response
    {
        $this->authorize('control', $browserSession);

        if ($browserSession->purpose !== BrowserSessionPurpose::ManualTakeover
            || $browserSession->status !== BrowserSessionStatus::HumanControl
            || $browserSession->run === null) {
            throw ValidationException::withMessages(['automation' => 'This manual takeover session is no longer active.']);
        }

        return Inertia::render('retailer-connections/takeover', [
            'run' => [
                'id' => $browserSession->run->id,
                'shopping_list_revision' => $browserSession->run->frozen_snapshot['revision'] ?? null,
            ],
            'session' => [
                'id' => $browserSession->id,
                'live_view_endpoint' => route('browser-sessions.live-view', $browserSession),
                'expires_at' => $browserSession->expires_at?->toIso8601String(),
                'recording_enabled' => $browserSession->recording_enabled,
            ],
            'return_url' => route('meal-plans.shopping.show', $browserSession->run->shoppingList->meal_plan_id),
        ]);
    }

    public function finish(
        Request $request,
        BrowserSession $browserSession,
        FinishAutomationTakeover $finish,
    ): RedirectResponse {
        $run = $browserSession->run;

        if ($run === null) {
            throw ValidationException::withMessages(['automation' => 'This cart run is no longer available.']);
        }

        $returnUrl = route('meal-plans.shopping.show', $run->shoppingList->meal_plan_id);
        $finish->handle($browserSession, $request->user());

        return redirect($returnUrl)->with('success', 'Manual control ended. Chef will reconcile the actual cart before continuing.');
    }
}
