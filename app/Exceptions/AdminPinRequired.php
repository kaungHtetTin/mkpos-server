<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminPinRequired extends HttpException
{
    public function __construct(string $message, public readonly bool $allowOwnerRecovery = false)
    {
        parent::__construct(403, $message);
    }
}
