<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Contracts;

use LEXO\LaravelHealthEndpoints\Support\CheckResult;

interface HealthCheck
{
    /** URL-safe unique name, [a-z0-9-]+, e.g. "disk-space". */
    public function name(): string;

    /** Perform the check. Throwing = fail (message taken from the exception). */
    public function run(): CheckResult;
}
