<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class NamedFixtureCheck extends Check
{
    protected ?string $name = 'custom-name';

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
