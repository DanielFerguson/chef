<?php

namespace App\Enums;

enum PreferenceProvenance: string
{
    case Stated = 'stated';
    case Default = 'default';
    case Inferred = 'inferred';
    case Feedback = 'feedback';
}
