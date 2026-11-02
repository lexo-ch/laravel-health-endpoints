<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class NewlinePathCheck extends Check implements HasCustomRoute
{
    public function path(): string
    {
        return "x\n";
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
