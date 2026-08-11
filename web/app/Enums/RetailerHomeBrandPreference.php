<?php

namespace App\Enums;

enum RetailerHomeBrandPreference: string
{
    case Allow = 'allow';
    case Prefer = 'prefer';
    case Avoid = 'avoid';
}
