<?php

namespace App\Enums;

enum PreparationNoticeKind: string
{
    case Defrost = 'defrost';
    case Marinate = 'marinate';
    case Soak = 'soak';
    case Rest = 'rest';
    case AdvancePrep = 'advance_prep';
    case Other = 'other';
}
