<?php
namespace App\Model;

use PHPerformance\Core\Database;
use Doctrine\DBAL\ParameterType;

class EventScheduler
{
    /**
     * Schedules a database event to run a specific SQL query at a given time. Note, this doesn't support recurring events at the
     * moment, but it could be added fairly easily if needed
     *
     * @param string $eventName The name of the event to schedule. Must not exceed 53 characters and should not contain spaces.
     * @param int $scheduleAt The UNIX timestamp when the event is scheduled to run.
     * @param string $query The SQL query to execute when the event triggers.
     * @param string $comment An optional comment for the event, limited to 64 characters.
     * @param array $params An optional associative array of parameters to bind to the query. This will be an array of values. Because of
     * this, if params is not empty, the query must contain question marks to bind, not named parameters.
     * @return bool Returns true if the event is successfully scheduled, false otherwise.
     */

    public static function scheduleEvent(string $eventName, int $scheduleAt, string $query, string $comment = '', array $params = []): bool
    {
        $eventName = preg_replace('/\s+/', '', $eventName); // remove spaces from event name
        // eventName is capped at 53 due to the unixtimestamp being appended to it and 64 characters is the max identifier length
        // event comments get truncated at 64 characters
        if (empty($eventName) || strlen($eventName) > 53 || empty($query) || strlen($comment) > 64 || $scheduleAt < time()) {
            return false;
        }

        $uniqueEventName = $eventName . "_" . time(); // want a unique name for each event

        // A semicolon at the end of the query will cause an error. I know we typically don't use semicolons in our queries, but
        // if a multi statement query is passed in, all the statements except for the last one need a semicolon. This will just 
        // remove the possibility of causing that error by removing the last semicolon
        $query = rtrim($query, ';');

        $sql = "
            CREATE EVENT IF NOT EXISTS $uniqueEventName
            ON SCHEDULE AT FROM_UNIXTIME(?)
            COMMENT ?
            DO
            BEGIN
                $query;
            END;
        ";

        // since we are using positional parameters, these need to be positional as well as we can't mix
        // named and positional parameters in the same query
        $stmt = Database::prepare($sql);
        $stmt->bindValue(1, $scheduleAt, ParameterType::INTEGER);
        $stmt->bindValue(2, $comment, ParameterType::STRING);

        $position = 3; 
        foreach ($params as $value) {
            $stmt->bindValue($position++, $value);
        }

        $stmt->executeQuery();

        // not sure how rowCount() works with CREATE EVENT queries, so instead return true if we did not have an exception thrown
        return true;
    }

    /**
     * Get a list of currently scheduled events. Events are returned with the fields event_name, created, execute_at, and event_comment.
     * @param string $prefix If provided, only return events with names that start with this prefix.
     * @return array
     */
    public static function getScheduledEvents(string $prefix = null): array
    {
        $query = "SELECT event_name, created, execute_at, event_comment FROM information_schema.events";

        if ($prefix !== null) {
            $query .= " WHERE event_name LIKE :prefix";
        }

        $stmt = Database::prepare($query);

        if ($prefix !== null) {
            $stmt->bindValue(':prefix', "$prefix%", ParameterType::STRING);
        }

        $result = $stmt->executeQuery();
        return $result->fetchAllAssociative();
    }

    /**
     * Cancel a scheduled event.
     * @param string $eventName The name of the event to cancel.
     * @return bool True if the event was successfully cancelled, false otherwise.
     */
    public static function cancelEvent(string $eventName): bool
    {
        $stmt = Database::prepare("DROP EVENT IF EXISTS $eventName");
        $stmt->executeQuery();
        
        return true; 
    }
}