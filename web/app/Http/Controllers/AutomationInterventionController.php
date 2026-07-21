<?php

namespace App\Http\Controllers;

use App\Actions\Automation\ResolveAutomationIntervention;
use App\Models\AutomationIntervention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomationInterventionController extends Controller
{
    public function update(
        Request $request,
        AutomationIntervention $automationIntervention,
        ResolveAutomationIntervention $resolve,
    ): RedirectResponse {
        $validated = $request->validate([
            'choice' => ['required', 'string', 'in:merge,replace,cancel,retry,skip,accept_substitution,accept_product'],
            'product' => ['nullable', 'array'],
        ]);
        $resolve->handle($automationIntervention, $request->user(), $validated);

        return to_route('meal-plans.shopping.show', $automationIntervention->run->shoppingList->meal_plan_id);
    }
}
