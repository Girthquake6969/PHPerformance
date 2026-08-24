<?php

namespace App\Model;

use PHPerformance\Core\Database;
use Doctrine\DBAL\Exception\DriverException;

class ActionAttempts {
    public static function insertActionAttemptRecord(string $eventName, string $attemptIp, ?int $userId): bool
    {
        $userQueryText = $userId !== null ? ", user_id = :uid" : "";

        $query = Database::prepare(<<<SQL
            INSERT INTO action_attempts SET
                event_name = :name,
                attempt_ip = :ip$userQueryText
        SQL);

        $query->bindValue(":name", $eventName);
        $query->bindValue(":ip", $attemptIp);

        if ($userId !== null) {
            $query->bindValue(":uid", $userId);
        }

        try {
            $query->executeQuery();
        } catch (DriverException $e) {
            return false;
        }

        return true;
    }

    public static function hasMetAttemptLimit(string $eventName, string $attemptIp, ?int $userId, int $attemptLimit = 5, int $timeframeMinutes = 30): bool
    {
        // need a combination of user id and attempt ip where applicable in case they jump around using a vpn
        // Case 1: have IP and no user id. Possible on support tickets (contact us)
        // Case 2: have both IP and user id. Possible in both places but requirement for failed logins
        $userQueryText = $userId !== null ? " OR user_id = :uid" : "";

        // we store these in datetime rather than unix timestamps. In the future, we should swap that to unix but for now
        // instead of doing the timezone set here, delegate the time check to mysql
        $query = Database::prepare(<<<SQL
            SELECT COUNT(*)
            FROM action_attempts
            WHERE
                event_name = :name AND
                attempt_timestamp > DATE_SUB(NOW(), INTERVAL :minutes MINUTE) AND
                (attempt_ip = :ip$userQueryText)
        SQL);

        $query->bindValue(":name", $eventName);
        $query->bindValue(":minutes", $timeframeMinutes);
        $query->bindValue(":ip", $attemptIp);

        if ($userId !== null) {
            $query->bindValue(":uid", $userId);
        }

        try {
            $result = $query->executeQuery();
            $count = $result->fetchOne();
        } catch (DriverException $e) {
            // treat attempt limit as met if we hit an exception
            return true;
        }

        return $count > $attemptLimit;
    }
}