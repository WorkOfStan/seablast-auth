<?php

declare(strict_types=1);

namespace Seablast\Auth\Exceptions;

use Exception;

final class UserException extends Exception
{
    /** @api */
    public function __construct(string $message = 'Unknown exception related to the user management.')
    {
        parent::__construct($message);
    }
}
