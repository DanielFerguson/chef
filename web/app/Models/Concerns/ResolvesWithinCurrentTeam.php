<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ResolvesWithinCurrentTeam
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $activeTeamId = auth()->check() ? auth()->user()->current_team_id : 0;

        return $query
            ->where('team_id', $activeTeamId)
            ->where($field ?? $this->getRouteKeyName(), $value);
    }
}
