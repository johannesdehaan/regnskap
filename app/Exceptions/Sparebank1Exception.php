<?php

namespace App\Exceptions;

use RuntimeException;

class Sparebank1Exception extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $oauthError = null)
    {
        parent::__construct($message);
    }
}
