<?php

namespace App\Voice\Contracts;

use App\Models\VoiceSession;

interface RealtimeSessionBroker
{
    public function connect(VoiceSession $session, string $offerSdp): string;
}
