<?php

namespace App\Voice\Testing;

use App\Models\VoiceSession;
use App\Voice\Contracts\RealtimeSessionBroker;

class FakeRealtimeSessionBroker implements RealtimeSessionBroker
{
    /** @var array<int, array{session_id: int, offer_sdp: string}> */
    public array $connections = [];

    public string $answerSdp = "v=0\r\no=OpenAI 1 1 IN IP4 127.0.0.1\r\ns=Chef test answer\r\nt=0 0\r\n";

    public function connect(VoiceSession $session, string $offerSdp): string
    {
        $this->connections[] = [
            'session_id' => $session->id,
            'offer_sdp' => $offerSdp,
        ];

        return $this->answerSdp;
    }
}
