<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->teams()->whereKey($user->current_team_id)->exists()) {
            $firstTeam = $user->teams()->orderBy('teams.id')->first();
            $user->forceFill(['current_team_id' => $firstTeam?->id])->save();
            $user->setRelation('currentTeam', $firstTeam);
        }

        return $next($request);
    }
}
