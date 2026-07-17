<?php

namespace App\Enums;

enum VoiceSessionStatus: string
{
    case Connecting = 'connecting';
    case Active = 'active';
    case Ended = 'ended';
    case Failed = 'failed';
}
