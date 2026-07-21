<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class CartProductPlanPolicy
{
    use AuthorizesTeamOwnedModel;
}
