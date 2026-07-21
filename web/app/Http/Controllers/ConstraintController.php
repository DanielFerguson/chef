<?php

namespace App\Http\Controllers;

use App\Actions\Households\RecordConstraint;
use App\Actions\Households\RemoveConstraint;
use App\Actions\Households\UpdateConstraint;
use App\Enums\ConstraintKind;
use App\Models\Constraint;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConstraintController extends Controller
{
    public function store(Request $request, RecordConstraint $record): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $validated = $request->validate([
            'person_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::enum(ConstraintKind::class)],
            'subject' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', 'string', 'max:80'],
            'explicitly_confirmed' => ['accepted'],
        ]);
        $person = isset($validated['person_id'])
            ? Person::query()->where('team_id', $team->id)->findOrFail((int) $validated['person_id'])
            : null;

        $record->handle(
            team: $team,
            user: $request->user(),
            kind: ConstraintKind::from($validated['kind']),
            subject: $validated['subject'],
            directlyConfirmed: true,
            person: $person,
            details: $validated['details'] ?? null,
            severity: $validated['severity'] ?? null,
        );

        return back();
    }

    public function update(Request $request, Constraint $constraint, UpdateConstraint $update): RedirectResponse
    {
        $this->authorize('update', $constraint);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'string', 'max:80'],
            'explicitly_confirmed' => ['accepted'],
        ]);
        $update->handle(
            constraint: $constraint,
            user: $request->user(),
            subject: $validated['subject'],
            details: $validated['details'] ?? null,
            severity: $validated['severity'] ?? null,
        );

        return back();
    }

    public function destroy(Request $request, Constraint $constraint, RemoveConstraint $remove): RedirectResponse
    {
        $this->authorize('delete', $constraint);
        $remove->handle($constraint, $request->user());

        return back();
    }
}
