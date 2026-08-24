<?php

namespace PHPerformance\Exceptions;

use Exception;

class InsufficientPermissionsException extends Exception {
    public function __construct(?string $message = null, int $code = 0)
    {
        if ($message === null) {
            $message = "You do not have the required permissions to perform this action.";
        }

        parent::__construct($message, $code);
    }
}
