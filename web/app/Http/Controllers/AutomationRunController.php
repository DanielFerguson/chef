<?php

namespace App\Http\Controllers;

use App\Actions\Automation\ControlAutomationRun;
use App\Actions\Automation\StartCartPreparation;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use App\Models\Retailer;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomationRunController extends Controller
{
    public function store(Request $request, ShoppingList $shoppingList, StartCartPreparation $start): RedirectResponse
    {
        $validated = $request->validate([
            'retailer_id' => ['required', 'integer', 'exists:retailers,id'],
            'browser_connection_uuid' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        $retailer = Retailer::query()->whereKey($validated['retailer_id'])->where('active', true)->firstOrFail();
        $connection = BrowserConnection::query()
            ->where('team_id', $shoppingList->team_id)
            ->where('uuid', $validated['browser_connection_uuid'])
            ->firstOrFail();
        $start->handle($shoppingList, $request->user(), $retailer, $connection, $validated['expected_revision']);

        return back();
    }

    public function update(Request $request, AutomationRun $automationRun, ControlAutomationRun $control): RedirectResponse
    {
        $validated = $request->validate([
            'control' => ['required', 'string', 'in:pause,resume,cancel,takeover,complete'],
        ]);
        $control->handle($automationRun, $request->user(), $validated['control']);

        return back();
    }
}
