<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use LEXO\LaravelHealthEndpoints\Contracts\HasTargets;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use Throwable;

abstract class TargetedCheck extends Check implements HasTargets
{
    public function run(): CheckResult
    {
        $targets = $this->targets();

        if ($targets === []) {
            return CheckResult::fail('No targets configured');
        }

        $failed = 0;

        foreach ($targets as $target) {
            try {
                if (! $this->runTarget($target)->isOk()) {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        return $failed > 0
            ? CheckResult::fail(sprintf('%d of %d targets failed', $failed, count($targets)))
            : CheckResult::ok();
    }
}
