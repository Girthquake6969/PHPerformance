<?php

namespace App\Message;

use InvalidArgumentException;

/**
 * Abuse Symfony's async messaging system to queue any functionality we want to be handled. This way we avoid having to re-set the environment for each "threaded" event.
 * Built to match the proposed structure for scheduled functions. The scheduled_functions table can still be used to pipe events here at a later time.
 * Not gonna lie, the Symfony docs were a big letdown here. 
 */
class AsyncProcess {
    private string $className;
    private string $functionName;
    private int $retries;
    private array $arguments;

    // default to 0 retries
    public function __construct(string $className, string $functionName, int $retries = 0, array $arguments = []) {
        if ($retries < 0) {
            throw new InvalidArgumentException("Retry count must be a positive integer or 0");
        }

        $this->className = $className;
        $this->functionName = $functionName;
        $this->retries = $retries;
        $this->arguments = $arguments;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getFunctionName(): string
    {
        return $this->functionName;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}