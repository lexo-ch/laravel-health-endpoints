<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\HealthChecks\Nested;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class NestedCheck extends Check
{
    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
