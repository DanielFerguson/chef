<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class AutomationRunItemPolicy
{
    use AuthorizesTeamOwnedModel;
}
