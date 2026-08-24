<?php

namespace PHPerformance\Exceptions;

use Exception;

/**
 * Error type to specify when an event must be rescheduled. Meant for use with Chargebee events, but can obviously be used beyond that
 */
class RescheduleRequiredException extends Exception {
    private int $rescheduleTimestamp;
    private bool $forMaintenance;

    public function __construct(int $rescheduleTimestamp, ?string $message = null, bool $forMaintenance = true)
    {
        // generic default message
        if ($message === null) {
            $message = "The requested action cannot be performed at this time and must be rescheduled.";
        }

        $this->rescheduleTimestamp = $rescheduleTimestamp;
        $this->forMaintenance = $forMaintenance;

        parent::__construct($message, 0);
    }

    /**
     * Return the unix timestamp the event should be rescheduled
     * @return int
     */
    public function getRescheduleTimestamp(): int
    {
        return $this->rescheduleTimestamp;
    }

    public function thrownForMaintenance(): bool
    {
        return $this->forMaintenance;
    }
}
