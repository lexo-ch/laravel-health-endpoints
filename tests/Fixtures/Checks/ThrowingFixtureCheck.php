<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use RuntimeException;

final class ThrowingFixtureCheck extends Check
{
    public function __construct(private readonly string $message = 'boom') {}

    public function run(): CheckResult
    {
        throw new RuntimeException($this->message);
    }
}
