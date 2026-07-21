<?php

namespace App\Enums;

enum ExistingCartDecision: string
{
    case Merge = 'merge';
    case Replace = 'replace';
    case Cancel = 'cancel';
}
