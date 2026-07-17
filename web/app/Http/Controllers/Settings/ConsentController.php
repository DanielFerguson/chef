<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Privacy\RecordConsentChoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ConsentController extends Controller
{
    public function update(Request $request, RecordConsentChoice $record): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['product_analytics', 'beta_research'])],
            'status' => ['required', Rule::in(['granted', 'declined', 'revoked'])],
        ]);
        $team = $request->user()->currentTeam;

        abort_if($team === null, 404);

        $record->handle($team, $request->user(), $validated['kind'], $validated['status']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Privacy choice updated.']);

        return to_route('data-privacy.edit');
    }
}
