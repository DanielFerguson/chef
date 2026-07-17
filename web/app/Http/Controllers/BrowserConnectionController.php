<?php

namespace App\Http\Controllers;

use App\Actions\Automation\CreateBrowserPairing;
use App\Actions\Automation\RevokeBrowserConnection;
use App\Models\BrowserConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BrowserConnectionController extends Controller
{
    public function store(Request $request, CreateBrowserPairing $create): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $pairing = $create->handle($team, $request->user());

        return back()->with('browser_pairing_code', $pairing['pairing_code']);
    }

    public function destroy(Request $request, BrowserConnection $browserConnection, RevokeBrowserConnection $revoke): RedirectResponse
    {
        $revoke->handle($browserConnection, $request->user());

        return back();
    }
}
