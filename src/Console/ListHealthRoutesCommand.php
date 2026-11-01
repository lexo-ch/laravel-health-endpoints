<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Console;

use Illuminate\Console\Command;
use LEXO\LaravelHealthEndpoints\Contracts\HasTargets;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Support\Json;
use LEXO\LaravelHealthEndpoints\Support\MessageSanitizer;
use LEXO\LaravelHealthEndpoints\Support\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'lhe:routes', description: 'List the available health check endpoints')]
final class ListHealthRoutesCommand extends Command
{
    protected $signature = 'lhe:routes {--json : Output machine-readable JSON}';

    protected $description = 'List the available health check endpoints';

    public function handle(HealthCheckRegistry $registry, Settings $settings): int
    {
        try {
            $checks = $registry->all();
            $customRoutes = $registry->customRoutes();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $protection = $this->protection($settings);
        $failures = [];
        $rows = [
            $this->row('/lhe/v1', null, null, $protection),
            $this->row('/lhe/v1/ping', null, null, $protection),
        ];

        foreach ($checks as $name => $check) {
            $rows[] = $this->row('/lhe/v1/'.$name, $name, null, $protection);

            if ($check instanceof HasTargets) {
                try {
                    $targets = $check->targets();
                } catch (Throwable $e) {
                    $targets = [];
                    $failures[] = sprintf('Could not list targets of check "%s": %s', $name, MessageSanitizer::fromThrowable($e));
                }

                foreach ($targets as $target) {
                    $rows[] = $this->row('/lhe/v1/'.$name.'/'.$target, $name, $target, $protection);
                }
            }
        }

        foreach ($customRoutes as $path => $name) {
            $rows[] = $this->row('/lhe/v1/'.$path, $name, null, $protection);
        }

        $warnings = [...$failures, ...$this->warnings($settings)];

        if ($this->option('json')) {
            $this->line(Json::pretty(['routes' => $rows, 'warnings' => $warnings]));

            return self::SUCCESS;
        }

        $this->table(
            ['Method', 'URL', 'Check', 'Target', 'Protection'],
            array_map(static fn (array $r): array => [$r['method'], $r['url'], $r['check'] ?? '-', $r['target'] ?? '-', $r['protection']], $rows),
        );

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{method: string, url: string, check: string|null, target: string|null, protection: string}
     */
    private function row(string $path, ?string $check, ?string $target, string $protection): array
    {
        return [
            'method' => 'GET|HEAD',
            'url' => url($path),
            'check' => $check,
            'target' => $target,
            'protection' => $protection,
        ];
    }

    private function protection(Settings $settings): string
    {
        $token = $settings->token() !== null || $settings->tokenMisconfigured();
        $ips = $settings->allowedIps() !== [];

        return match (true) {
            $token && $ips => 'token + ip allowlist',
            $token => 'token',
            $ips => 'ip allowlist',
            default => 'none',
        };
    }

    /**
     * @return list<string>
     */
    private function warnings(Settings $settings): array
    {
        $warnings = [];

        if (app()->environment('production') && ! $settings->isProtected()) {
            $warnings[] = 'Health endpoints are PUBLIC in production (no LHE_TOKEN and no LHE_ALLOWED_IPS): only minimal status is exposed. Set a token or an IP allowlist.';
        }

        if ($settings->tokenMisconfigured()) {
            $warnings[] = 'The configured token is not a string: every request is denied (401). Set LHE_TOKEN to a string.';
        }

        $token = $settings->token();
        if ($token !== null && strlen($token) < 32) {
            $warnings[] = 'LHE_TOKEN is shorter than 32 characters. Generate a strong one with `php artisan lhe:token`.';
        }

        // Both stores fail silently at runtime by design, so a typo would otherwise go unnoticed.
        if (! $this->storeConfigured($settings->cacheStore())) {
            $warnings[] = sprintf('LHE_CACHE_STORE "%s" is not a store in config/cache.php: rate limiting, result caching and failure/recovery tracking do not work.', $settings->cacheStore());
        }

        $heartbeat = $settings->heartbeatStore();
        if ($heartbeat !== null && ! $this->storeConfigured($heartbeat)) {
            $warnings[] = sprintf('LHE_HEARTBEAT_STORE "%s" is not a store in config/cache.php: the scheduler and queue checks always fail.', $heartbeat);
        }

        return $warnings;
    }

    private function storeConfigured(string $store): bool
    {
        $stores = config('cache.stores');

        return is_array($stores) && is_array($stores[$store] ?? null);
    }
}
