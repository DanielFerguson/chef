<?php

namespace App\Http\Controllers;

use App\Actions\Automation\DecideAutomationApproval;
use App\Models\AutomationApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomationApprovalController extends Controller
{
    public function update(Request $request, AutomationApproval $automationApproval, DecideAutomationApproval $decide): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $decide->handle($automationApproval, $request->user(), $validated['decision'] === 'approve', $validated['note'] ?? null);

        return back();
    }
}
