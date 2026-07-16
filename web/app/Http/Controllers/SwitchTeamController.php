<?php

namespace App\Http\Controllers;

use App\Actions\Teams\SwitchCurrentTeam;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchTeamController extends Controller
{
    public function __invoke(Request $request, Team $team, SwitchCurrentTeam $switchCurrentTeam): RedirectResponse
    {
        $switchCurrentTeam->handle($request->user(), $team);

        return back();
    }
}
