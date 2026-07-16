<?php

namespace App\Enums;

enum ShoppingListStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
}
