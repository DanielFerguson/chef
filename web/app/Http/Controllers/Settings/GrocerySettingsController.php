<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Retailers\BuildRetailerPurchasePolicyView;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRetailerPurchasePolicyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrocerySettingsController extends Controller
{
    public function edit(Request $request, BuildRetailerPurchasePolicyView $buildView): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);

        return Inertia::render('settings/groceries', $buildView->handle($team, $request->user()));
    }

    public function update(
        UpdateRetailerPurchasePolicyRequest $request,
        UpdateRetailerPurchasePolicy $updatePolicy,
    ): RedirectResponse {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $validated = $request->validated();
        $updatePolicy->handle(
            $team,
            $request->user(),
            RetailerProvider::Coles,
            RetailerHomeBrandPreference::from($validated['home_brand_preference']),
            RetailerBulkPreference::from($validated['bulk_preference']),
            RetailerOrganicPreference::from($validated['organic_preference']),
            $validated['preferred_brands'],
            $validated['default_basket_target_cents'] ?? null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grocery preferences updated.']);

        return to_route('groceries.edit');
    }
}
