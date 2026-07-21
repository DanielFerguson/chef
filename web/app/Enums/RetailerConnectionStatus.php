<?php

namespace App\Enums;

enum RetailerConnectionStatus: string
{
    case PendingLogin = 'pending_login';
    case Checking = 'checking';
    case Connected = 'connected';
    case ReauthenticationRequired = 'reauthentication_required';
    case Disconnected = 'disconnected';
    case Revoked = 'revoked';
    case Error = 'error';
}
