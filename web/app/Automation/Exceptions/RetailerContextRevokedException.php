<?php

namespace App\Automation\Exceptions;

use RuntimeException;

class RetailerContextRevokedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The saved retailer browser context is no longer available.');
    }
}
