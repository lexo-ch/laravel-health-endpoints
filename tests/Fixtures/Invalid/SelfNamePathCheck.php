<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class SelfNamePathCheck extends Check implements HasCustomRoute
{
    protected ?string $name = 'self-name';

    public function path(): string
    {
        return 'self-name';
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
