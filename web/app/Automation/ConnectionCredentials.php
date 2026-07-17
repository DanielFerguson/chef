<?php

namespace App\Automation;

class ConnectionCredentials
{
    public function pairingHash(string $code): string
    {
        return hash_hmac('sha256', $this->normaliseCode($code), (string) config('app.key'));
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }
}
