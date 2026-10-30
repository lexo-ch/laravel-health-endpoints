<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Registry;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class CountingCheck extends Check
{
    public static int $made = 0;

    public function __construct()
    {
        self::$made++;
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
