<?php

namespace App\Enums;

enum RetailerConnectionStatus: string
{
    case PendingAuthentication = 'pending_authentication';
    case Connected = 'connected';
    case ReauthenticationRequired = 'reauthentication_required';
    case Disconnected = 'disconnected';
    case Failed = 'failed';
}
