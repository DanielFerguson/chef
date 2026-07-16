<?php

namespace App\Enums;

enum ConstraintKind: string
{
    case Allergy = 'allergy';
    case Medical = 'medical';
    case Dietary = 'dietary';
    case Religious = 'religious';
    case Accessibility = 'accessibility';
    case Other = 'other';
}
