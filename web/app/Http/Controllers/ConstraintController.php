<?php

namespace App\Http\Controllers;

use App\Actions\Households\RecordConstraint;
use App\Models\Constraint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConstraintController extends Controller
{
    public function update(Request $request, Constraint $constraint, RecordConstraint $record): RedirectResponse
    {
        abort_unless($request->user()->memberships()->where('team_id', $constraint->team_id)->exists(), 403);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'string', 'max:80'],
            'explicitly_confirmed' => ['accepted'],
        ]);
        $record->handle(
            $constraint->team,
            $request->user(),
            $constraint->kind,
            $validated['subject'],
            true,
            $constraint->person,
            $validated['details'] ?? null,
            $validated['severity'] ?? null,
        );

        if ($constraint->subject !== $validated['subject']) {
            $constraint->delete();
        }

        return back();
    }

    public function destroy(Request $request, Constraint $constraint): RedirectResponse
    {
        abort_unless($request->user()->memberships()->where('team_id', $constraint->team_id)->exists(), 403);
        $constraint->delete();

        return back();
    }
}
