<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class StubRedisCheck extends Check
{
    protected ?string $name = 'redis';

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
