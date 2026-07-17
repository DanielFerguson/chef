<?php

namespace App\Http\Controllers;

use App\Actions\Cooking\ReviewPreferenceCandidate;
use App\Enums\PreferenceCandidateStatus;
use App\Models\PreferenceCandidate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceCandidateController extends Controller
{
    public function update(Request $request, PreferenceCandidate $preferenceCandidate, ReviewPreferenceCandidate $review): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([PreferenceCandidateStatus::Accepted->value, PreferenceCandidateStatus::Dismissed->value])],
        ]);
        $review->handle($preferenceCandidate, $request->user(), PreferenceCandidateStatus::from($validated['decision']));

        return back();
    }
}
