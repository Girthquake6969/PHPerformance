<?php

namespace PHPerformance\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use PHPerformance\Exceptions\SystemException;
use InvalidArgumentException;
use Throwable;

class Database {
    private static string $host;
    private static string $username;
    private static string $password;
    private static string $database;

    /**
     * store callable and args to be run after a transaction is committed or rolled back.
     * @var [callable function, mixed[] args][]
     */
    private static array $queuedProcesses;

    // still public to handle some testing purposes, but should not be otherwise used directly.
    // TODO: cave and add getter/setter methods instead
    public static Connection $connection;

    public static bool $inTransaction;

    /**
     * Sets up new connection based on setup data Symfony pulls from the env file. Now actually creates the connection, removing the need for a separate connect function.
     * Essentially the "constructor"
     * @throws \PHPerformance\Exceptions\SystemException
     * @return void
     */
    public static function setEnvironment(): void
    {
        // relying on Symfony to populate the connection information found in .env.local for main connection setup
        if (empty($_ENV['DATABASE_URL'])) {
            throw new SystemException("Environment variables not set correctly. No db connection information present.");
        }

        // TODO: take a look at options for caching or storing these creds to a prod-only secure file so we aren't string processing every request
        $connectionParams = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($_ENV['DATABASE_URL']);

        /** @var \Doctrine\DBAL\Connection */
        self::$connection = DriverManager::getConnection($connectionParams);

        // now that we are past code that would throw an exception, safe to save parsed connection info
        self::$host = $connectionParams['host'];
        self::$username = $connectionParams['user'];
        self::$password = $connectionParams['password'];
        self::$database = $connectionParams['dbname'];
        self::$inTransaction = false;
        self::$queuedProcesses = [];
    }

    public static function setCustomEnvironment(string $username, string $password, string $database, string $host = 'localhost') {
        $connectionArray = ['driver' => 'pdo_mysql'];

        self::$host = $connectionArray['host'] = $host;
        self::$username = $connectionArray['user'] = $username;
        self::$password = $connectionArray['password'] = $password;
        self::$database = $connectionArray['dbname'] = $database;
        self::$connection = DriverManager::getConnection($connectionArray);
        self::$inTransaction = false;
        self::$queuedProcesses = [];
    }

    /**
     * Manually trigger a (re)connection using the current class variables
     * @return void
     */
    public static function connect(): void
    {
        self::$connection = DriverManager::getConnection([
            'host' => self::$host,
            'user' => self::$username,
            'password' => self::$password,
            'dbname' => self::$database,
            'driver' => 'pdo_mysql'
        ]);
    }

    /**
     * Safely substitute an array of values into a query with an IN clause
     * NOTE: for queries where you need to make other substitutions with bindValue, make sure to use "?" as the wildcards and pass the values as args to this function
     * @return Statement
     */
    public static function prepareWithInClause(string $query, array $values, string $wildcard = ':placeholders', ...$extras): Statement
    {
        // start by checking for invalid args
        if (empty($query)) {
            throw new InvalidArgumentException("Attempting to prepare an empty query string.");
        }

        if (empty($wildcard)) {
            throw new InvalidArgumentException("Provided empty wildcard for parameter binding.");
        }

        // save value count for later and ensure it is above 0
        $count = \count($values);

        if ($count === 0) {
            throw new InvalidArgumentException("Provided an empty array for IN clause.");
        }

        // Normally I wouldn't verify the wildcard for performance, but it is important when considering $extras later
        $passedWildCardPosition = strpos($query, $wildcard);

        if ($passedWildCardPosition === false) {
            throw new InvalidArgumentException('Passed placeholder value does not exist in passed query.');
        }

        // if the query already has "?" placeholders, make sure they are all after the IN placeholder or this function will not work as expected
        $genericWildcardPosition = strpos($query, '?');

        if ($genericWildcardPosition !== false && $genericWildcardPosition < $passedWildCardPosition) {
            throw new InvalidArgumentException("Attempting to safely insert an IN clause to a query that already has '?' placeholders. Move these placeholders below the IN clause or this function will not work as expected.");
        }

        // verify we have $extras if there are "?" placeholders
        $hasExtras = \count($extras) > 0;

        if ($genericWildcardPosition !== false && !$hasExtras) {
            throw new InvalidArgumentException("Provided query contains ? wildcard(s), but no arguments to substitute in for them.");
        }

        $placeholders = implode(',', array_fill(0, $count, '?'));

        // throws error on failure regardless of PDO error setting (which thankfully defaults to error anyway in PHP 8+)
        $stmt = self::prepare(str_replace($wildcard, $placeholders, $query));

        // bind values to the placeholders
        foreach ($values as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }

        // now bind any args passed in $extras
        if ($hasExtras) {
            // bindValue is offset by 1, so factor that in immediately
            $indexOffset = $count + 1;

            foreach ($extras as $index => $value) {
                $stmt->bindValue($index + $indexOffset, $value);
            }
        }

        return $stmt;
    }

    /**
     * Create a batch insert with options for INSERT IGNORE or ON DUPLICATE KEY UPDATE if needed
     * be wary of using null for $maxEntriesPerStatement, as you will be at the mercy of our MySQL max packet size
     * Assumes uniform keys across all provided rows
     * @param string $tableName
     * @param array $keyValueData 2 dimensional array where each entry represents one row being inserted. For each row, the key will be the column name and value will hold "value" and "type", where type is the ParameterType that should be used on binding
     * @param string|null $onDuplicateKeyString null or a string containing everything after "ON DUPLICATE KEY UPDATE" for all relevant updates
     * @param bool $insertIgnore whether or not to use INSERT IGNORE. Incompatible with non-null $onDuplicateKeyMap
     * @param int|null $maxEntriesPerStatement max entries per batch or null if they should all be inserted in one statement regardless of record count
     * @return void
     * @throws InvalidArgumentException if both insertIgnore and onDuplicateKeyMap are truthy or if no rows are provided for insertion
     */
    public static function batchInsert(string $tableName, array $keyValueData, ?string $onDuplicateKeyString = null, bool $insertIgnore = false, ?int $maxEntriesPerStatement = 1000): void
    {
        if ($insertIgnore && $onDuplicateKeyString !== null) {
            throw new InvalidArgumentException("Cannot use INSERT IGNORE and ON DUPLICATE KEY UPDATE in the same query. Choose one or the other.");
        }

        if (empty($keyValueData)) {
            throw new InvalidArgumentException("Must provide at least one row of data to insert.");
        }

        if (0 === $keyCount = (\count($keyValueData[0] ?? []))) {
            throw new InvalidArgumentException("Must provide at least one column to insert per row.");
        }

        // pre-split $keyValueData by $maxEntriesPerStatement if applicable. If null, will just be one "split"
        $maxEntriesPerStatement ??= \count($keyValueData);
        $splitBatches = array_chunk($keyValueData, $maxEntriesPerStatement);
        $ignoreString = $insertIgnore ? "IGNORE" : "";
        $onDuplicateKeyClause = null === $onDuplicateKeyString ? "" : "ON DUPLICATE KEY UPDATE $onDuplicateKeyString";
        $keyString = implode(', ', array_keys($keyValueData[0]));

        foreach ($splitBatches as $batch) {
            $valueIndex = 1;
            $valuePlaceholder = implode(',', array_fill(0, \count($batch), '(' . implode(',', array_fill(0, $keyCount, '?')) . ')'));
            $query = Database::prepare(<<<SQL
                INSERT $ignoreString INTO $tableName ($keyString)
                VALUES $valuePlaceholder
                $onDuplicateKeyClause
            SQL);

            // TODO: rework to ensure each batch entry is uniform. Probably through new objects and an object-oriented approach
            foreach ($batch as $columns) {
                foreach ($columns as $valueData) {
                    $query->bindValue($valueIndex++, $valueData['value'], $valueData['type'] ?? ParameterType::STRING);
                }
            }

            $query->executeQuery();
        }
    }

    /**
     * Wrapper for Connection->executeQuery. Intended for use with 0-parameter queries, but technically does support parameters with 2 array args.
     * I am not personally jazzed about that scheme, but I get that PHP makes this difficult.
     * NOTE: does NOT support named parameters; only ?
     * @param string $sql query string
     * @param array $params array of values to apply as substitutions in order. Default empty
     * @param ArrayParameterType[]|ParameterType[] $paramTypes optional array of type specifications for the values provided in $params. Seemingly required for array params (for use with IN clause)
     * @return Result
     */
    public static function query(string $sql, array $params = [], array $paramTypes = []): Result
    {
        return self::$connection->executeQuery($sql, $params, $paramTypes);
    }

    /**
     * Wrapper for Connection->prepare
     * @param string $sql
     * @return Statement
     */
    public static function prepare(string $sql): Statement
    {
        return self::$connection->prepare($sql);
    }

    public static function lastInsertId(): string
    {
        return self::$connection->lastInsertId();
    }

    // TODO: implement the various connection fetch functions, which perform the full loop of query creation, parameterization, execution, and result parsing.
    // Would not hurt to also check out executeStatement, which returns the number of affected rows instead of results. Great for update/delete queries
    // also options for insert, update, and delete, but at that point just use Doctrine. In this house, we use raw sql queries.

    /******************************************************
                         TRANSACTIONS
    *******************************************************/

    public static function startTransaction()
    {
        // transactions started by the PDO version of this function will automatically rollback on failure. Not sure about this one
        if (self::$inTransaction) {
            return;
        }

        try {
            self::$connection->beginTransaction();
            self::$inTransaction = true;
        } catch (Throwable $e) {
            // silently continue
        }
    }

    public static function commitTransaction()
    {
        try {
            self::$connection->commit();
        } catch (Throwable $e) {
            // do nothing. there is no active transaction
        }

        // always make sure we are not reporting we are in a transaction
        self::$inTransaction = false;
        self::runQueuedFunctions();
    }

    public static function rollbackTransaction(?Throwable $exception = null)
    {
        try {
            self::$connection->rollback();
        } catch (Throwable $e) {
            // do nothing. there is no active transaction
        }

        // always make sure we are not reporting we are in a transaction
        self::$inTransaction = false;
        self::runQueuedFunctions();

        if (null !== $exception) {
            throw $exception;
        }
    }

    public static function delayOrRunDependentFunction(callable $function, mixed ...$args)
    {
        if (!self::$inTransaction) {
            return $function(...$args);
        }

        self::$queuedProcesses[] = [
            "function" => $function,
            "args" => $args
        ];
    }

    public static function runQueuedFunctions(): void
    {
        while ($queuedFunction = array_pop(self::$queuedProcesses)) {
            try {
                $function = &$queuedFunction['function'];
                $function(...$queuedFunction['args']);
            } catch (Throwable $e) {
                $encodedArgs = json_encode($queuedFunction['args']);

                // is_string check fixes an issue where Closures that throw exceptions create another exception here when trying to cast it to string
                new SystemException("Failed running queued function " . (\is_string($function) ? $function : "Anonymous") . " with args $encodedArgs");
            }
        }
    }

    /**
     * Function for unit testing. Gives the number of processes in the queue.
     * @return int
     */
    public static function getQueuedProcessCount(): int
    {
        return \count(self::$queuedProcesses);
    }

    /******************************************************
                            LOCKING
    *******************************************************/

    public static function getNamedLock(string $lockName, int $timeout = 3): bool
    {
        $lockQuery = self::prepare("SELECT GET_LOCK(:lockName, :timeout)");
        $lockQuery->bindValue(":lockName", self::$database . "-$lockName");
        $lockQuery->bindValue(":timeout", $timeout, ParameterType::INTEGER);
        $result = $lockQuery->executeQuery();

        // returns 1 if lock aquisition was successful, 0 if not, and null if there was a failure. Treat null and 0 responses the same
        return $result->fetchOne() == 1;
    }

    public static function releaseNamedLock(string $lockName): void
    {
        $lockQuery = self::prepare("SELECT RELEASE_LOCK(:lockName)");
        $lockQuery->bindValue(":lockName", self::$database . "-$lockName");
        $result = $lockQuery->executeQuery();
    }

    /**
     * Release all named locks held by the current session
     * @return void
     */
    public static function releaseAllNamedLocks():void
    {
        self::query("SELECT RELEASE_ALL_LOCKS()");
    }


    /******************************************************
                      MIGRATIONS/BACKUPS
    *******************************************************/

    // REWORK BACKUP FUNCTIONS TO BE LESS HARDCODED TO ETF
    /**
     * Make a complete backup of the currently connected db. Completes primary db first and TOV socket once successful
     * @return int
     */
    public static function backupDbToFile(string $exportPath): int
    {
        // only continue if the provided string is actually a directory on the system
        if (!is_dir($exportPath)) {
            throw new InvalidArgumentException("Provided directory string either does not exist or is not a directory");
        }

        $now = time();

        return self::mysqlDump("$exportPath/$now.sql", self::$username, self::$database, self::$host, self::$password);
    }

    /**
     * Attempt to restore the current database from a backup file
     * NOTE: SHOULD ONLY BE USED ON PRODUCTION IN ACTUAL EMERGENCIES WITH LOST DATA. MAINTENANCE MODE CANNOT PREVENT ERRORS FOR THE END USER
     * @param int $backupTimestamp timestamp of the backup (will be the file name)
     * @param string $importPath file path where the backup file is located
     * @param string|null $customDbName custom db name to use when restoring the backup. For when you want the backup to exist within mysql as another db
     * @return int response code from the mysql call
     */
    public static function restoreDbFromBackupFile(int $backupTimestamp, string $importPath, ?string $customDbName = null): int
    {
        // validate that timestamp is valid and that a dump file exists for the provided backup timestamp
        if ($backupTimestamp < 1) {
            throw new InvalidArgumentException("Backup timestamp must be a positive integer");
        }

        if ($backupTimestamp > time()) {
            throw new InvalidArgumentException("Cannot restore a backup from the future");
        }

        // make sure this backup exists
        $dumpFile = "$importPath$backupTimestamp.sql";

        if (!file_exists($dumpFile)) {
            throw new InvalidArgumentException("No backup found associated with the provided directory and timestamp");
        }

        echo "Dump file exists. Starting restoration...\n";

        $schemaToUse = $customDbName ?? self::$database;

        self::query("DROP DATABASE IF EXISTS $schemaToUse");
        self::query("CREATE DATABASE $schemaToUse");

        if (0 !== $responseCode = self::insertDbFromFile(self::$host, self::$username, self::$password, $schemaToUse, $dumpFile)) {
            echo "Failed to restore db from file. Received exit code $responseCode\n";
        } else {
            echo "Db restoration complete!\n";
        }

        return $responseCode;
    }

    /**
     * Run mysqldump from within PHP
     * @param string $dumpPath
     * @param string $username
     * @param string $schemaName
     * @param string $hostname
     * @param string $password
     * @param string[] $ignoreTables
     * @return int response code returned from mysqldump
     */
    private static function mysqlDump(string $dumpPath, string $username, string $schemaName, string $hostname, string $password, string ...$ignoreTables): int
    {
        $outputArr = null;
        $code = null;
        $ignoreTablesClause = "";

        foreach ($ignoreTables as $ignoreTable) {
            // dont allow empty strings
            if (empty($ignoreTable)) {
                continue;
            }

            $ignoreTablesClause .= "--ignore-table=\"$schemaName.$ignoreTable\" ";
        }

        exec("mysqldump --user=\"$username\" --password=\"$password\" --host=\"$hostname\" {$ignoreTablesClause}--single-transaction --skip-lock-tables $schemaName > $dumpPath 2>&1", $outputArr, $code);

        return $code;
    }

    /**
     * Attempt to take a mysqldump-generated sql file and insert it to a provided database by name
     * @param string $username
     * @param string $password
     * @param string $database
     * @param string $filePath
     * @return int response code from the mysql command
     */
    private static function insertDbFromFile(string $host, string $username, string $password, string $database, string $filePath): int
    {
        $code = null;
        exec("mysql --host=\"" . $host . "\" --user=\"" . $username . "\" --password=\"" . $password . "\" " . $database . " < $filePath", $outputArr, $code);

        return $code;
    }

    public static function migrateDb(string $exportDir, string $hostname, string $password, string $schemaName, ?callable $callbackFunc = null, string ...$ignoreTables) {
        if (!is_dir($exportDir)) {
            throw new InvalidArgumentException("");
        }

        // 150 minutes. should be more than enough
        if (!set_time_limit(9000)) {
            echo 'Alert: Failed to set new runtime time limit. Likely a permissions issue. This script will still run, but runtime default is unchanged.' . PHP_EOL . PHP_EOL;
        }

        $dumpPath = "{$exportDir}migration.sql";
        
        try {
            echo "Beginning dump from $schemaName@$hostname....";

            // TODO: remove assumption that schema name is always the same as username since this is now intended to be general-purpose    
            $startTime = microtime(true);  
  
            if (0 !== self::mysqlDump($dumpPath, $schemaName, $schemaName, $hostname, $password, ...$ignoreTables)) {
                echo "\nThere was an issue dumping from $schemaName@$hostname. Migration failed. Check the contents of the dump file for the error\n";
                return;
            }

            $endTime = microtime(true);
            echo "Done. Time elapsed: " . round($endTime - $startTime, 2) . " seconds\n";

            // clear current data so we dont have foreign key constraint failures on import
            $tempDatabase = time() . "_temp"; // will rename tables from this to crchaney_etf after insertion

            self::query("DROP DATABASE IF EXISTS $tempDatabase");
            self::query("CREATE DATABASE $tempDatabase");

            echo "Inserting into $tempDatabase....";

            $startTime = microtime(true);

            if (0 !== $code = self::insertDbFromFile(self::$host,self::$username, self::$password, $tempDatabase, $dumpPath)) {
                echo "\nThere was an issue inserting the dump received. Expected response code 1 but received code $code\nMigration failed\n";
                return;
            }

            $endTime = microtime(true);

            echo "Done inserting into $tempDatabase. Time elapsed: " . round($endTime - $startTime, 2) . " seconds\n";
            echo "Renaming tables from $tempDatabase to " . self::$database . "\n";

            $startTime = microtime(true);

            self::query("DROP DATABASE " . self::$database);
            self::query("CREATE DATABASE " . self::$database);
            self::query("SET FOREIGN_KEY_CHECKS=0");

            $envDatabase = self::$database;
            $renameStatements = self::query(<<<SQL
                SELECT CONCAT('RENAME TABLE $tempDatabase.', table_name, ' TO $envDatabase.', table_name, '; ') as concat_stmt
                FROM information_schema.TABLES 
                WHERE table_schema='$tempDatabase';
            SQL)->fetchAllAssociative();

            foreach ($renameStatements as $s) {
                self::query($s["concat_stmt"]);
            }

            self::query("SET FOREIGN_KEY_CHECKS=1");
            self::query("DROP DATABASE $tempDatabase");

            $endTime = microtime(true);

            echo "Done renaming tables. Time elapsed: " . round($endTime - $startTime, 2) . " seconds\n";
            echo "Resetting connection to local db....";
            self::connect();
            echo "Done\n";

            if (null !== $callbackFunc) {
                $callbackFunc();
            }

            echo "Done! Don't forget to delete the dump file! ($dumpPath)\n\n";
        } catch (Throwable $e) {
            echo "\nError encountered during migration: {$e->getMessage()}";
        }
    }

    /******************************************************
                        COMPATIBILITY
    *******************************************************/

    /**
     * Compatibility function replacing functionality provided by PDOStatement->fetchAll(PDO::FETCH_GROUP), as this mode is not supported by DBAL
     * The correct way to do this would probably be to extend Statement and Result, but for one function that is used one time, I think this is just fine
     * Working off the sample provided by user uncaught in this github thread about the issue: https://github.com/doctrine/dbal/issues/4206
     * @param \Doctrine\DBAL\Result $result
     * @return array matching output from PDOStatement->fetchAll(PDO::FETCH_GROUP)
     */
    public static function fetchAllGrouped(Result $result): array
    {
        $responseData = [];

        while (false !== $row = $result->fetchAssociative()) {
            // first column in the result set will form the basis of our key
            // NOTE: this does remove the key from the source row. That may lead to unintended consequences compared to the original
            $groupingKey = array_shift($row);

            if (!\array_key_exists($groupingKey, $responseData)) {
                $responseData[$groupingKey] = [$row];
                continue;
            }

            $responseData[$groupingKey][] = $row;
        }

        return $responseData;
    }
}