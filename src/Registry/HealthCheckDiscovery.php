<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Registry;

use FilesystemIterator;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

final class HealthCheckDiscovery
{
    /**
     * PSR-4 mapping of *.php files below $directory onto $namespace.
     *
     * @return list<class-string<HealthCheck>>
     */
    public function discover(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $root = rtrim($directory, '/\\');
        $prefix = trim($namespace, '\\');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $found = [];

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = ($prefix === '' ? '' : $prefix.'\\').str_replace(['/', '\\'], '\\', $relative);

            try {
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->isInstantiable() && $reflection->implementsInterface(HealthCheck::class)) {
                /** @var class-string<HealthCheck> $name */
                $name = $reflection->getName();
                $found[] = $name;
            }
        }

        sort($found);

        return $found;
    }
}
