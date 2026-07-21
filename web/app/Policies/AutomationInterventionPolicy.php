<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class AutomationInterventionPolicy
{
    use AuthorizesTeamOwnedModel;
}
