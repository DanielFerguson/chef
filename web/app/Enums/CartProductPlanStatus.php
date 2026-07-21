<?php

namespace App\Enums;

enum CartProductPlanStatus: string
{
    case Discovering = 'discovering';
    case NeedsReview = 'needs_review';
    case Ready = 'ready';
    case Frozen = 'frozen';
    case Superseded = 'superseded';
    case Failed = 'failed';
}
