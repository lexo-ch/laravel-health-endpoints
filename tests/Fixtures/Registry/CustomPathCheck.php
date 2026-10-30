<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class CustomPathCheck extends Check implements HasCustomRoute
{
    public function path(): string
    {
        return 'billing/stripe';
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
