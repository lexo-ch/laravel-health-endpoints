<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Contracts;

interface HasCustomRoute
{
    /** Path relative to /lhe/v1, e.g. "billing/stripe". */
    public function path(): string;
}
