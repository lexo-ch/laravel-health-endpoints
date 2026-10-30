<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use LEXO\LaravelHealthEndpoints\Contracts\HasTargets;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\MessageSanitizer;
use Throwable;

abstract class TargetedCheck extends Check implements HasTargets
{
    public function run(): CheckResult
    {
        $targets = $this->targets();
        $failed = 0;

        foreach ($targets as $target) {
            try {
                $result = $this->runTarget($target);
            } catch (Throwable $e) {
                $result = CheckResult::fail(MessageSanitizer::fromThrowable($e));
            }

            if (! $result->isOk()) {
                $failed++;
            }
        }

        return $failed > 0
            ? CheckResult::fail(sprintf('%d of %d targets failed', $failed, count($targets)))
            : CheckResult::ok();
    }
}
