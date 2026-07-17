<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Privacy\DeleteTeam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TeamDeleteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamDeletionController extends Controller
{
    public function __invoke(TeamDeleteRequest $request, DeleteTeam $delete): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_if($team === null, 404);

        if (! hash_equals($team->name, $request->validated('team_name'))) {
            throw ValidationException::withMessages(['team_name' => 'Enter the family name exactly as shown.']);
        }

        $delete->handle($team, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'The family and its retained data were deleted.']);

        return to_route('dashboard');
    }
}
