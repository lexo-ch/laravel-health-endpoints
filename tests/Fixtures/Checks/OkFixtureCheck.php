<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class OkFixtureCheck extends Check
{
    public function run(): CheckResult
    {
        return CheckResult::ok('fine', ['k' => 'v']);
    }
}
