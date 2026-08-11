<?php

namespace App\Enums;

enum RetailerWorkerResultStatus: string
{
    case Succeeded = 'succeeded';
    case Blocked = 'blocked';
    case Retryable = 'retryable';
    case Uncertain = 'uncertain';
    case Failed = 'failed';
}
