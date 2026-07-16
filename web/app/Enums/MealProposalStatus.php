<?php

namespace App\Enums;

enum MealProposalStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Replaced = 'replaced';
}
