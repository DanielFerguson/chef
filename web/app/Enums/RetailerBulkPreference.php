<?php

namespace App\Enums;

enum RetailerBulkPreference: string
{
    case Allow = 'allow';
    case Avoid = 'avoid';
}
