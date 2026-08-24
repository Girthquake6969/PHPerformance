<?php

namespace App\MessageHandler;

use App\Message\AsyncProcess;
use PHPerformance\Core\SchedulableFunctions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AsyncProcessHandler {
    public function __invoke(AsyncProcess $process) {
        require_once __DIR__ . '/../../public/config.php';
        // let exceptions bubble up. The worker will continue for all exception types except StopWorkerException
        SchedulableFunctions::runScheduledFunction($process->getClassName(), $process->getFunctionName(), $process->getRetries(), ...$process->getArguments());
    }
}
