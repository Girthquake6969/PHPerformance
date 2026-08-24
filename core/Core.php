<?php

/**
 * A class holding broad core functionality needed across the site and not just one specific place
 */

namespace PHPerformance\Core;
use App\Message\AsyncProcess;
use App\MessageHandler\AsyncProcessHandler;
use GdImage;
use InvalidArgumentException;
use PHPerformance\Core\Database;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineSender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use PHPerformance\Exceptions\SystemException;


class Core {
    // constants
    public const PASSWORD_MIN_LENGTH = 8;

    public const ASYNC_QUEUE_HIGH_PRIORITY = 'async';
    public const ASYNC_QUEUE_LOW_PRIORITY = 'asyncPriorityLow';

    private static MessageBusInterface $asyncProcessBus;

    /**
     * Create the bus that will dispatch async events to the worker to process in the background
     * @return void
     */
    public static function initializeAsyncProcessBus(): void
    {
        // need a dummy SendersLocator
        // figuring this out was a bitch and a half. Bless up to xdebug
        $sendersLocator = new class implements SendersLocatorInterface {
            public function getSenders(Envelope $envelope): iterable
            {
                // need to manually check envelope for stamps
                // starting with the default configuration. Any changes made to messenger.yaml will need to be manually made here
                // very lame since I thought the whole point of TransportNamesStamp was to override where these went..... Whatever
                $connection = new Connection([
                    'queue_name' => $envelope->last(TransportNamesStamp::class) === null ? Core::ASYNC_QUEUE_HIGH_PRIORITY : Core::ASYNC_QUEUE_LOW_PRIORITY
                ], Database::$connection);

                return [
                    'async' => new DoctrineSender($connection, new Serializer())
                ];
            }
        };

        $asyncProcessHandler = new AsyncProcessHandler();
        self::$asyncProcessBus = new TraceableMessageBus(new MessageBus([
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware(new HandlersLocator([
                AsyncProcess::class => [$asyncProcessHandler]
            ]))
        ]));
    }

    /**
     * Attempt to send a process for processing by the current worker(s) on the system
     * @param string $qualifiedClassName full namespaced class name (yourClassHere::class)
     * @param string $functionName function name to run in the SchedulableFunctions class. Function must exist or an error will be thrown when the worker attempts to execute.
     * @param int $retries number of times to retry the function on failure. Defaults to 0, meaning no retries
     * @param bool $highPriority boolean to assign priority to the task. Defaults to true. When false, a worker will only pick up the job if there are no high-pri items waiting
     * @param bool $ignoreTransactionStatus boolean to allow a calling function to ignore the current db transaction (if applicable) and insert the job regardless. Defaults to false, which ensures the messages are inserted even if a rollback occurs.
     * @param mixed[] $functionArgs any arguments to pass to the function. If number of required args does not match what is sent, an exception is thrown in the worker.
     * @return void
     */
    public static function dispatchAsyncProcess(string $qualifiedClassName, string $functionName, int $retries = 0, bool $highPriority = true, bool $ignoreTransactionStatus = false, array $functionArgs = []): void
    {
        if ($ignoreTransactionStatus) {
            self::$asyncProcessBus->dispatch(new AsyncProcess($qualifiedClassName, $functionName, $retries, $functionArgs), $highPriority ? [] : [new TransportNamesStamp(self::ASYNC_QUEUE_LOW_PRIORITY)]);
            return;
        }
        
        Database::delayOrRunDependentFunction(self::$asyncProcessBus->dispatch(...), new AsyncProcess($qualifiedClassName, $functionName, $retries, $functionArgs), $highPriority ? [] : [new TransportNamesStamp(self::ASYNC_QUEUE_LOW_PRIORITY)]);
    }

    /**
     * Schedule an async process to happen at a provided timestamp
     * @param string $qualifiedClassName full namespaced class name (yourClassHere::class)
     * @param string $functionName
     * @param int $unixTimestamp
     * @param int $retries number of times to retry the function on failure. Defaults to 0, meaning no retries
     * @param bool $highPriority boolean to assign priority to the task. Defaults to true. When false, a worker will only pick up the job if there are no high-pri items waiting
     * @param bool $ignoreTransactionStatus boolean to allow a calling function to ignore the current db transaction (if applicable) and insert the job regardless. Defaults to false, which ensures the messages are inserted even if a rollback occurs.
     * @param mixed[] $functionArgs
     * @throws \InvalidArgumentException
     * @return void
     */
    public static function scheduleAsyncProcess(string $qualifiedClassName, string $functionName, int $unixTimestamp, int $retries = 0, bool $highPriority = true, bool $ignoreTransactionStatus = false, array $functionArgs = []): void
    {
        $currentTime = time();

        // ensure the unix timestamp is actually in the future
        if ($unixTimestamp <= $currentTime) {
            throw new InvalidArgumentException("Cannot schedule a process to be completed in the past");
        }

        // calculate difference between now and provided time, in milliseconds
        // if this actually ends up needing to be used more than once, create as a separate function
        $millisecondsDifference = $unixTimestamp * 1000 - $currentTime * 1000;
        $stamps = [new DelayStamp($millisecondsDifference)];

        if (!$highPriority) {
            $stamps[] = new TransportNamesStamp(self::ASYNC_QUEUE_LOW_PRIORITY);
        }

        if ($ignoreTransactionStatus) {
            self::$asyncProcessBus->dispatch(new AsyncProcess($qualifiedClassName, $functionName, $retries, $functionArgs), $stamps);
            return;
        }

        Database::delayOrRunDependentFunction(self::$asyncProcessBus->dispatch(...), new AsyncProcess($qualifiedClassName, $functionName, $retries, $functionArgs), $stamps);
    }

    /**
     * Generates a random code of the specified length.
     *
     * The code is generated using a set of characters that excludes capital O, I, and lowercase l, i, o, and 0 (zero) to avoid confusion.
     *
     * @param int $length The length of the generated code ID. Defaults to 14.
     * @return string The generated random code ID.
     */
    public static function generateRandomCode(int $length = 14): string
    {
        // no capital O,I or lowercase l,i,o or 0(zero)
        return self::randomStringFromPool('123456789ABCDEFGHJKLMNPQRSTUVWXYZ', $length);
    }

    /**
     * Generate random code from a pool of password recommended characters using cryptographically secure randomness
     * Ensures there is at least one a piece of uppercase/lowercase letters, numbers, and special characters 
     * @param int $length length of the output password
     * @return string
     */
    public static function generatePassword(int $length = 9): string
    {
        // NOTE: true absolute minimum length would be 4 characters because we need at least one of each type, but we should ideally be at least 8.
        if ($length < self::PASSWORD_MIN_LENGTH) {
            throw new InvalidArgumentException("Password length must be at least " . self::PASSWORD_MIN_LENGTH . " characters long.");
        }

        // need to ensure this always includes at least one upper case letter, one lower case letter, one number, and one special character, with enough variation to not present obvious patterns
        $remainingLength = $length;
        $characterPools = ['abcdefghijklmnopqrstuvwxyz', '?!#$^&*', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '0123456789'];
        $poolCount = count($characterPools);
        $generatedPassword = "";

        for ($i = 0; $i < $poolCount; $i++) {
            // need to make sure we always leave room for at least one character from the remaining pools each
            $remainingPoolCount = $poolCount - ($i + 1);
            $maxSelectableForCurrentPool = $remainingLength - $remainingPoolCount;
            $minSelectableForCurrentPool = $remainingPoolCount === 0 ? $remainingLength : 1;
            $numToSelect = $maxSelectableForCurrentPool === $minSelectableForCurrentPool ? $minSelectableForCurrentPool : random_int($minSelectableForCurrentPool, $maxSelectableForCurrentPool);
            $generatedPassword .= self::randomStringFromPool($characterPools[$i], $numToSelect);
            $remainingLength -= $numToSelect;
        }

        return str_shuffle($generatedPassword);
    }

    /**
     * Given a provided pool of characters, generate a random string of $length length, consisting of characters from the pool
     * @param string $pool
     * @param int $length
     * @return string
     */
    public static function randomStringFromPool(string $pool, int $length = 9): string
    {
        $charactersLength = strlen($pool);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            // Using random_int() for cryptographically secure randomness
            $randomString .= $pool[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Add days in seconds to a provided timestamp. Negative day values will subtract days from the timestamp
     * @param int $days the number of days to add to the provided timestamp
     * @param mixed $unixTimestamp unix timetstamp to perform operation on. Defaults to current time
     * @return int modified timestamp
     */
    public static function addDaysToDate(int $days, ?int $unixTimestamp = null) {
        // default to current time if none is passed
        if ($unixTimestamp === null) {
            $unixTimestamp = time();
        }

        return $unixTimestamp + 86400 * $days;
    }

    /**
     * convert any number into a pretty string for display. Replaced prettyPrintDollarAmount
     * @param float $amount
     * @param string $prefix string value to prepend to the prettified number
     * @param string $suffix string value to append to the prettified number
     * @param int $decimalDigits number of digits to include to the right of the decimal. MUST be >= 0 (default 2)
     * @return string
     */
    public static function prettyPrintNumber(float $amount, string $prefix = "", string $suffix = "", int $decimalDigits = 2): string
    {
        if ($decimalDigits < 0) {
            throw new InvalidArgumentException("Cannot submit a negative value for decimal count");
        }

        return $prefix . number_format($amount, $decimalDigits) . $suffix;
    }

    public static function displayIntAsOrdinal(int $number): string
    {
        $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if ((($number % 100) >= 11) && (($number % 100) <= 13)) {
            return $number . 'th';
        }
        
        return $number . $ends[$number % 10];
    }

    /**
     * Peform one or more string replacements on a source string. Primarily for emails but may have other uses
     * @param string $source source string to perform replacements on
     * @param array $replacementKeyValues key-value pairs. The function will attempt to replace instances of the key in the source string with the associated value
     * @throws \InvalidArgumentException
     * @return string modified source with replacements made
     */
    public static function performStringReplacements(string $source, array $replacementKeyValues): string
    {
        if (empty($source)) {
            throw new InvalidArgumentException('Cannot perform substitution on empty string');
        }

        if (empty($replacementKeyValues)) {
            throw new InvalidArgumentException('Cannot perform string substitution with no values to substitute');
        }

        foreach ($replacementKeyValues as $key => $value) {
            $source = str_replace($key, $value, $source);
        }

        return $source;
    }

    /**
     * Remove expired database backups from beyond a specified threshold of days
     * @param int $daysSinceCreation lifetime of dump in days. Defaults to 7 days so files older than a week get cleared
     * @throws \InvalidArgumentException
     * @throws \PHPerformance\Exceptions\SystemException
     * @return void
     */
    public static function deleteExpiredDbBackups(int $daysSinceCreation = 7): void
    {
        if ($daysSinceCreation < 1) {
            throw new InvalidArgumentException('$daysSinceCreation must be a positive integer');
        }

        // acknowledging that this sucks but as a dependency, it seems to be the best I can do without getting too into the weeds
        $basePath = __DIR__ .'/../';
        $dumpFiles = [...glob("{$basePath}dumps/*.sql"), ...glob("{$basePath}dumps/socket/*.sql")];

        if (!\array_key_exists(0, $dumpFiles)) {
            throw new SystemException("Failed to grab dump files for automatic deletion");
        }

        $expirationCutoff = strtotime("-$daysSinceCreation day");

        foreach ($dumpFiles as $file) {
            // check the timestamp the file was modified
            if ($expirationCutoff >= filemtime($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Simple function to get the IP address associated with the current request. Will provide unexpected results on non-http requests (scripts)
     * @return string
     */
    public static function getSessionIp(): string
    {
        if ($_ENV['APP_ENV'] === 'test' || $_ENV['APP_ENV'] === 'local') {
            return "127.0.0.1";
        }

        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Generate a cryptographically secure random set of bytes of $numBytes length and encode in base64
     * Intended for use in generating basic vendor authentication keys
     * NOTE: does not mean the output string will be 32 characters long. That is simply the number of bytes generated before base64 encode
     * @param int $numBytes
     * @return string
     * @throws InvalidArgumentException
     */
    public static function generateSecureRandomKey(int $numBytes = 32): string
    {
        if ($numBytes < 1) {
            throw new InvalidArgumentException("numBytes must be a positive integer (32 recommended)");
        }

        return base64_encode(random_bytes($numBytes));
    }

    /**
     * When applying text to an image, reduce font size until the provided text will fit within the bounds of the provided image
     * @param \GdImage $image
     * @param string $pathToFontFile
     * @param string $textToDisplay
     * @param int $fontSize
     * @param int $minFontSize
     * @param float $maxWidthMultiplier determines the percentage of the source image width to max out the text width against
     * @return int
     */
    public static function reduceTextFontSizeToFitImage(
        GdImage $image,
        string $pathToFontFile,
        string $textToDisplay,
        int $fontSize,
        int $minFontSize = 10,
        float $maxWidthMultiplier = 0.7
    ): int
    {
        $maxWidth = imagesx($image) * $maxWidthMultiplier;
        $getTextWidth = function ($fontSize, $font, $text) {
            $bbox = imagettfbbox($fontSize, 0, $font, $text);
            return $bbox[4] - $bbox[0];
        };

        while ($getTextWidth($fontSize, $pathToFontFile, $textToDisplay) > $maxWidth && $fontSize >= $minFontSize) {
            $fontSize--; 
        }

        return $fontSize;
    }

    /**
     * Given an image resource, save the raw data from conversion to png and return as string
     * @param \GdImage $image
     * @return string
     */
    public static function imageToRawData(GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $rawImageData = ob_get_contents();
        ob_end_clean();

        if (false === $rawImageData) {
            throw new SystemException("Failed to convert image resource to png data");
        }

        return $rawImageData;
    }

    /**
     * Formats the first name with the last initial.
     *
     * @param string $firstName The first name.
     * @param string $lastName The last name.
     * @return string The formatted first name with the last initial.
     */
    public static function formatFirstNameWithLastInitial(string $firstName, string $lastName): string
    {
        if (empty($firstName) || empty($lastName)) {
            return '';
        }

        $firstNameFormatted = ucfirst(strtolower($firstName));
        $lastInitial = strtoupper(substr($lastName, 0, 1));

        return "$firstNameFormatted $lastInitial.";
    }

    /**
     * Calculate and format human-readable time difference using DateTime objects
     * @param int $timestamp Unix timestamp
     * @return string Human-readable time difference (e.g., "3 days ago", "2 hours ago", "45 minutes ago", "just now")
     */
    public static function getTimeAgo(int $timestamp): string
    {
        static $now = null;
        if ($now === null) {
            $now = new \DateTime();
        }
        
        $dateTime = new \DateTime();
        $dateTime->setTimestamp($timestamp);
        $diff = $now->diff($dateTime);
        
        // invert === 0 means future date, invert === 1 means past date
        if ($diff->invert === 0) {
            // Future date - return 'just now'
            return 'just now';
        }
        
        // Past date (invert === 1) - calculate time difference
        if ($diff->days > 0) {
            return $diff->days . ($diff->days == 1 ? ' day' : ' days') . ' ago';
        }
        
        if ($diff->h > 0) {
            return $diff->h . ($diff->h == 1 ? ' hour' : ' hours') . ' ago';
        }
        
        if ($diff->i > 0) {
            return $diff->i . ($diff->i == 1 ? ' minute' : ' minutes') . ' ago';
        }
        
        // Less than a minute ago
        return 'just now';
    }

    /**
     * Parse a CSV file into an array. Can be numerically or associatively indexed per-line
     * @param string $relativeFilePath relative path to the csv file you'd like to open
     * @param bool $keyAssociative whether or not to key the fields of each line. Assumes the first line of the file provides the keys if this is true!
     * @throws InvalidArgumentException if the file cannot be found
     * @return array<array>
     */
    public static function csvToArray(string $relativeFilePath, bool $keyAssociative): array
    {
        if (false === $file = fopen($relativeFilePath, 'r')) {
            throw new InvalidArgumentException("Could not find $relativeFilePath or file does not have correct permissions to be accessed by this script.");
        }

        $results = [];

        // if we want an associative output, need to parse the first line as the keys
        if ($keyAssociative) {
            // if file is empty, just return at this point for parity with non-associative options
            if (false === $line = fgets($file)) {
                fclose($file);
                return $results;
            }

            // need to strip string characters " and ' if surrounding the entry
            $keys = str_getcsv($line);
        }

        while ($line = fgets($file)) {
            $parsedLine = str_getcsv($line);

            if ($keyAssociative) {
                $result = [];

                // swap numbered keys with corresponding value in $keys
                // falls back to original numbered index if keys is shorter than the data lines, for whatever reason
                // TODO: determine if it would make more sense to loop on keys instead of the line values. Only really matters in CSVs that are completely fucked
                foreach ($parsedLine as $index => $value) {
                    $result[$keys[$index] ?? $index] = $value === null ? null : str_replace(',', '', $value);
                }

                $results[] = $result;
                continue;
            }

            $results[] = $parsedLine;
        }

        fclose($file);
        return $results;
    }
}
