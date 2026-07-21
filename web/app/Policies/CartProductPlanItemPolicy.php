<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesTeamOwnedModel;

class CartProductPlanItemPolicy
{
    use AuthorizesTeamOwnedModel;
}
