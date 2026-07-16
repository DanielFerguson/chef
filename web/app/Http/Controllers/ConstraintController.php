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
        $this->authorize('update', $constraint);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'string', 'max:80'],
            'explicitly_confirmed' => ['accepted'],
        ]);
        $record->handle(
            team: $constraint->team,
            user: $request->user(),
            kind: $constraint->kind,
            subject: $validated['subject'],
            directlyConfirmed: true,
            person: $constraint->person,
            details: $validated['details'] ?? null,
            severity: $validated['severity'] ?? null,
        );

        if ($constraint->subject !== $validated['subject']) {
            $constraint->delete();
        }

        return back();
    }

    public function destroy(Request $request, Constraint $constraint): RedirectResponse
    {
        $this->authorize('delete', $constraint);
        $constraint->delete();

        return back();
    }
}
