<?php

namespace PHPerformance\Core;

use PHPerformance\Exceptions\SystemException;
use Throwable;

/**
 * A class with the sole purpose of running processes from the async worker queue; either immediately or after a specified delay.
 * Functions passed in must be public and static
 */
class SchedulableFunctions {
    /**
     * Self-contained function to check if a function exists and execute it with the provided args if so
     * NOTE: must be a STATIC FUNCTION!
     * @param string $qualifiedClassName fully qualified class name (complete, full namespace. Just pass in yourClassHere::class) 
     * @param string $functionName static function name within the provided class
     * @param int $retries
     * @param mixed[] $functionArgs
     * @throws \PHPerformance\Exceptions\SystemException
     * @return void
     */
    public static function runScheduledFunction(string $qualifiedClassName, string $functionName, int $retries = 0, mixed ...$functionArgs): void
    {
        // verify the function exists
        if (!method_exists($qualifiedClassName, $functionName)) {
            throw new SystemException("Attempting to run non-existent scheduled function '$qualifiedClassName'::'$functionName'", "Scheduled Function Error", true);
        }

        $attemptNumber = 0;

        // ensure this always runs at least once without having to set weird logic on attemptNumber when retries is 0
        do {
            try {
                // TIL call_user_func_array is pretty heavy and also needless after PHP 5.6. The same result can be achieved faster with uniform variable syntax
                $qualifiedClassName::$functionName(...$functionArgs);
                return;
            } catch (Throwable $e) {
                $attemptNumber++;
            }
        } while ($attemptNumber <= $retries);

        // unsuccessful after all retries (if applicable). Nothing left to do but log and throw exception
        throw new SystemException("Error encountered running scheduled function '$qualifiedClassName'::'$functionName': {$e->getMessage()}", "Scheduled Function Exception", true);
    }
}
