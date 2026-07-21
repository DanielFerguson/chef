<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class AutomationStepPolicy
{
    use AuthorizesTeamOwnedModel;
}
