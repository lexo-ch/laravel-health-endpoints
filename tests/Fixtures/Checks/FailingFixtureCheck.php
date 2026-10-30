<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class FailingFixtureCheck extends Check
{
    public function __construct(private readonly string $message = 'failed') {}

    public function run(): CheckResult
    {
        return CheckResult::fail($this->message);
    }
}
