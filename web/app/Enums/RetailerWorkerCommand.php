<?php

namespace App\Enums;

enum RetailerWorkerCommand: string
{
    case ProbeAuth = 'probe_auth';
    case SearchProducts = 'search_products';
    case InspectBasket = 'inspect_basket';
    case EnsureBasketEmpty = 'ensure_basket_empty';
    case EnsureBasketLine = 'ensure_basket_line';
    case ReleaseSession = 'release_session';
}
