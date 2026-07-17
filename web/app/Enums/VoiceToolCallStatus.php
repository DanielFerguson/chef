<?php

namespace App\Enums;

enum VoiceToolCallStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
