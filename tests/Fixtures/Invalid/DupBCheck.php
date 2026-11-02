<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class DupBCheck extends Check
{
    protected ?string $name = 'dup';

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
