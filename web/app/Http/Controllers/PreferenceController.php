<?php

namespace App\Http\Controllers;

use App\Actions\Households\RecordPreference;
use App\Enums\PreferenceSentiment;
use App\Models\Preference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function update(Request $request, Preference $preference, RecordPreference $record): RedirectResponse
    {
        $this->authorize('update', $preference);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'sentiment' => ['required', Rule::enum(PreferenceSentiment::class)],
            'strength' => ['required', 'integer', 'between:1,5'],
        ]);
        $record->handle(
            $preference->team,
            $request->user(),
            $validated['subject'],
            PreferenceSentiment::from($validated['sentiment']),
            $preference->provenance,
            $preference->person,
            $validated['strength'],
            $preference->confidence,
        );

        if ($preference->subject !== $validated['subject']) {
            $preference->delete();
        }

        return back();
    }

    public function destroy(Request $request, Preference $preference): RedirectResponse
    {
        $this->authorize('delete', $preference);
        $preference->delete();

        return back();
    }
}
