<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($team !== null, 404, 'Create a family before opening the workspace.');
        $this->authorize('view', $team);

        $people = $team->people()
            ->with('userLink.user:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn ($person) => [
                'id' => $person->id,
                'name' => $person->name,
                'has_account' => $person->userLink !== null,
                'email' => $person->userLink?->user?->email,
            ]);

        return Inertia::render('dashboard', [
            'household' => [
                'id' => $team->id,
                'name' => $team->name,
                'timezone' => $team->timezone,
                'people' => $people,
            ],
        ]);
    }
}
