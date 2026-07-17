<?php

namespace App\Enums;

enum PreferenceCandidateStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
