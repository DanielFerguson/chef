<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Privacy\ExportTeamData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamDataExportController extends Controller
{
    public function __invoke(Request $request, ExportTeamData $export): StreamedResponse
    {
        $team = $request->user()->currentTeam;

        abort_if($team === null, 404);

        $payload = $export->handle($team, $request->user());
        $filename = 'chef-family-'.$team->id.'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $filename,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
