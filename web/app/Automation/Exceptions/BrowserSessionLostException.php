<?php

namespace App\Automation\Exceptions;

use RuntimeException;

class BrowserSessionLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The retailer browser session ended before the current step could be verified.');
    }
}
