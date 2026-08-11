<?php

namespace App\Enums;

enum RetailerCandidateStatus: string
{
    case Eligible = 'eligible';
    case Rejected = 'rejected';
    case Stale = 'stale';
}
