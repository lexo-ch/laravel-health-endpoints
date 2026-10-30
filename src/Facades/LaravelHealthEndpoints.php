<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Facades;

use Illuminate\Support\Facades\Facade;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;

/**
 * @method static void register(string|HealthCheck $check)
 * @method static array<string, HealthCheck> all()
 * @method static HealthCheck|null find(string $name)
 * @method static array<string, string> customRoutes()
 *
 * @see HealthCheckRegistry
 */
final class LaravelHealthEndpoints extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HealthCheckRegistry::class;
    }
}
