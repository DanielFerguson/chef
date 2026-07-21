<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesTeamOwnedModel
{
    public function view(User $user, Model $model): bool
    {
        return $user->memberships()->where('team_id', $model->getAttribute('team_id'))->exists();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }
}
