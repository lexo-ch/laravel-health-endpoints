<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Contracts;

use LEXO\LaravelHealthEndpoints\Support\CheckResult;

interface HasTargets
{
    /** @return list<string> configured targets */
    public function targets(): array;

    public function runTarget(string $target): CheckResult;
}
