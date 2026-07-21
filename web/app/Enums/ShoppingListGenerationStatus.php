<?php

namespace App\Enums;

enum ShoppingListGenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
