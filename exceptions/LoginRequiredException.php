<?php

namespace PHPerformance\Exceptions;

use Exception;

class LoginRequiredException extends Exception
{
    public function __construct(?string $message = null, int $code = 0)
    {
        if ($message === null) {
            $message = "You must be logged in to perform this action.";
        }

        parent::__construct($message, $code);
    }
}
