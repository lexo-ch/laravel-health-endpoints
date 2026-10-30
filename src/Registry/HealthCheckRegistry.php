<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Registry;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LEXO\LaravelHealthEndpoints\Contracts\HasCustomRoute;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use LEXO\LaravelHealthEndpoints\Exceptions\InvalidHealthCheckException;

final class HealthCheckRegistry
{
    private const CHECKS_NS = 'LEXO\\LaravelHealthEndpoints\\Checks\\';

    /** @var list<string> */
    public const RESERVED = ['ping', 'database', 'redis', 'cache', 'storage', 'queue', 'scheduler', 'migrations', 'debug-mode'];

    private const BUILT_IN = [
        'database' => [self::CHECKS_NS.'DatabaseCheck', 'database.enabled'],
        'redis' => [self::CHECKS_NS.'RedisCheck', 'redis.enabled'],
        'cache' => [self::CHECKS_NS.'CacheCheck', 'cache.enabled'],
        'storage' => [self::CHECKS_NS.'StorageCheck', 'storage.enabled'],
        'queue' => [self::CHECKS_NS.'QueueCheck', 'queue.enabled'],
        'scheduler' => [self::CHECKS_NS.'SchedulerCheck', 'scheduler.enabled'],
        'migrations' => [self::CHECKS_NS.'MigrationsCheck', 'migrations.enabled'],
        'debug-mode' => [self::CHECKS_NS.'DebugModeCheck', 'debug_mode.enabled'],
    ];

    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*\z/';

    private const PATH_PATTERN = '/^[a-z0-9-]+(\/[a-z0-9-]+)*\z/';

    private const CONFIG = 'laravel-health-endpoints.';

    /** @var list<string|HealthCheck> */
    private array $programmatic = [];

    /** @var array<string, HealthCheck>|null */
    private ?array $checks = null;

    /** @var array<string, string> */
    private array $routes = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly HealthCheckDiscovery $discovery,
        /** @var array<string, array{string, string}>|null test seam: overrides the built-in map */
        private readonly ?array $builtInMap = null,
    ) {}

    /**
     * Built-in checks: name => [class, config enabled key]. Classes may not exist yet and are skipped then.
     *
     * @return array<string, array{string, string}>
     */
    public static function builtIn(): array
    {
        return self::BUILT_IN;
    }

    public function register(string|HealthCheck $check): void
    {
        $this->programmatic[] = $check;
        $this->checks = null;
    }

    /**
     * @return array<string, HealthCheck>
     */
    public function all(): array
    {
        return $this->checks ??= $this->build();
    }

    public function find(string $name): ?HealthCheck
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, string> path => check name
     */
    public function customRoutes(): array
    {
        $this->all();

        return $this->routes;
    }

    /**
     * @return array<string, HealthCheck>
     */
    private function build(): array
    {
        $candidates = [];

        foreach ($this->builtInMap ?? self::builtIn() as [$class, $key]) {
            if (class_exists($class) && $this->enabled($key, true)) {
                $candidates[] = $class;
            }
        }

        $configured = $this->config->get(self::CONFIG.'checks', []);
        foreach (is_array($configured) ? $configured : [] as $class) {
            if (! is_string($class) && ! $class instanceof HealthCheck) {
                throw InvalidHealthCheckException::notACheck(get_debug_type($class));
            }
            $candidates[] = $class;
        }

        if ($this->enabled('discover.enabled', true)) {
            $path = $this->config->get(self::CONFIG.'discover.path');
            $namespace = $this->config->get(self::CONFIG.'discover.namespace');

            if (is_string($path) && $path !== '' && is_string($namespace)) {
                $directory = $this->isAbsolute($path) ? $path : base_path($path);
                array_push($candidates, ...$this->discovery->discover($directory, $namespace));
            }
        }

        array_push($candidates, ...$this->programmatic);

        $checks = [];
        $owners = [];
        $seenClasses = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && isset($seenClasses[$candidate])) {
                continue;
            }

            $check = $this->resolve($candidate);

            if (is_string($candidate) && isset($seenClasses[$check::class])) {
                continue;
            }
            $seenClasses[$check::class] = true;

            $name = $check->name();

            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw InvalidHealthCheckException::invalidName($check::class, $name);
            }

            $builtIn = ($this->builtInMap ?? self::builtIn())[$name][0] ?? null;
            if (in_array($name, self::RESERVED, true) && $builtIn !== $check::class) {
                throw InvalidHealthCheckException::reservedName($check::class, $name);
            }

            if (isset($checks[$name])) {
                throw InvalidHealthCheckException::duplicateName($check::class, $name, $owners[$name]);
            }

            $checks[$name] = $check;
            $owners[$name] = $check::class;
        }

        $this->routes = $this->resolveRoutes($checks);

        return $checks;
    }

    /**
     * @param  array<string, HealthCheck>  $checks
     * @return array<string, string>
     */
    private function resolveRoutes(array $checks): array
    {
        $routes = [];

        foreach ($checks as $name => $check) {
            if (! $check instanceof HasCustomRoute) {
                continue;
            }

            $path = $check->path();

            if (preg_match(self::PATH_PATTERN, $path) !== 1) {
                throw InvalidHealthCheckException::invalidPath($check::class, $path);
            }

            $segment = explode('/', $path)[0];
            $taken = in_array($segment, self::RESERVED, true) || isset($checks[$segment]);
            if ($taken) {
                throw InvalidHealthCheckException::pathCollision($check::class, $path, $segment);
            }

            if (isset($routes[$path])) {
                throw InvalidHealthCheckException::duplicatePath($check::class, $path, $checks[$routes[$path]]::class);
            }

            $routes[$path] = $name;
        }

        return $routes;
    }

    private function resolve(string|HealthCheck $candidate): HealthCheck
    {
        if ($candidate instanceof HealthCheck) {
            return $candidate;
        }

        if (! class_exists($candidate) || ! is_subclass_of($candidate, HealthCheck::class)) {
            throw InvalidHealthCheckException::notACheck($candidate);
        }

        $check = $this->container->make($candidate);
        assert($check instanceof HealthCheck);

        return $check;
    }

    private function enabled(string $key, bool $default): bool
    {
        return filter_var($this->config->get(self::CONFIG.$key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
