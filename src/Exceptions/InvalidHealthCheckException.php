<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Exceptions;

use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use RuntimeException;

final class InvalidHealthCheckException extends RuntimeException
{
    public static function notACheck(string $class): self
    {
        return new self(sprintf('Class [%s] is not a health check. It must exist and implement %s.', $class, HealthCheck::class));
    }

    public static function invalidName(string $class, string $name): self
    {
        return new self(sprintf('Health check [%s] has invalid name "%s". Names must match [a-z0-9-]+ (lowercase, digits, single hyphens); override $name or name() to rename it.', $class, $name));
    }

    public static function reservedName(string $class, string $name): self
    {
        return new self(sprintf('Health check [%s] uses reserved name "%s". Rename it by overriding $name or name().', $class, $name));
    }

    public static function duplicateName(string $class, string $name, string $existing): self
    {
        return new self(sprintf('Health check name "%s" of [%s] is already registered by [%s]. Rename one of them by overriding $name or name().', $name, $class, $existing));
    }

    public static function invalidPath(string $class, string $path): self
    {
        return new self(sprintf('Health check [%s] has invalid custom path "%s". Paths must match [a-z0-9-]+(/[a-z0-9-]+)*; change path().', $class, $path));
    }

    public static function pathCollision(string $class, string $path, string $segment): self
    {
        return new self(sprintf('Custom path "%s" of [%s] collides with reserved or existing check name "%s". Change the first path segment in path().', $path, $class, $segment));
    }

    public static function duplicatePath(string $class, string $path, string $existing): self
    {
        return new self(sprintf('Custom path "%s" of [%s] is already registered by [%s]. Change path() of one of them.', $path, $class, $existing));
    }
}
