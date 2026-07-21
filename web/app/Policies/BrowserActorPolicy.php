<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class BrowserActorPolicy
{
    use AuthorizesTeamOwnedModel;
}
