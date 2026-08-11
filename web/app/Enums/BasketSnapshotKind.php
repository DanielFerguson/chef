<?php

namespace App\Enums;

enum BasketSnapshotKind: string
{
    case Baseline = 'baseline';
    case Final = 'final';
    case Restoration = 'restoration';
}
