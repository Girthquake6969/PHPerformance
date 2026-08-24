<?php

namespace PHPerformance\Exceptions;

use Exception;
use PHPerformance\Logging;

/**
 * Class to represent errors that happened during cron
 * @deprecated SystemException now supports custom codes for logging, removing the need for a separate error type doing the same thing
 */
class CronException extends Exception
{
    public function __construct(string $jobName, string $message, int $code = 0)
    {
        parent::__construct($message, $code);

        // sumo log the message
        $logData = [
            "code" => "Cron Error",
            "job" => $jobName,
            "message" => $message,
            "trace" => $this->getTraceAsString()
        ];

        Logging::sendLogToSumoLogic("Cron Error", $logData);
    }
}