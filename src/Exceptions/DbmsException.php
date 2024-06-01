<?php

declare(strict_types=1);

namespace Seablast\Auth\Exceptions;

use Exception;

final class DbmsException extends Exception
{
    /** @api */
    public function __construct(string $message = 'Unknown database management error.')
    {
        parent::__construct($message);
    }
}
