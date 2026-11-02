<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Exceptions;

use RuntimeException;

final class TargetNotFoundException extends RuntimeException
{
    public static function forTarget(string $check, string $target): self
    {
        return new self(sprintf('Target "%s" is not configured for health check "%s".', $target, $check));
    }

    public static function noTargets(string $check): self
    {
        return new self(sprintf('Health check "%s" has no targets.', $check));
    }
}
