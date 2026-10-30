<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Invalid;

use LEXO\LaravelHealthEndpoints\Checks\Check;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class NumericNameCheck extends Check implements HasCustomRoute
{
    protected ?string $name = '123';

    public function path(): string
    {
        return 'ok';
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}
