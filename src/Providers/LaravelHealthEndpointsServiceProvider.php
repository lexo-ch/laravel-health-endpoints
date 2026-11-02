<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use LEXO\LaravelHealthEndpoints\Checks\QueueCheck;
use LEXO\LaravelHealthEndpoints\Console\GenerateTokenCommand;
use LEXO\LaravelHealthEndpoints\Console\ListHealthRoutesCommand;
use LEXO\LaravelHealthEndpoints\Console\MakeHealthCheckCommand;
use LEXO\LaravelHealthEndpoints\Console\RunHealthChecksCommand;
use LEXO\LaravelHealthEndpoints\Http\Middleware\InjectServiceMarkers;
use LEXO\LaravelHealthEndpoints\Jobs\QueueHeartbeatJob;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Runner\StateTracker;
use LEXO\LaravelHealthEndpoints\Support\Heartbeats;
use LEXO\LaravelHealthEndpoints\Support\ResultCache;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class LaravelHealthEndpointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laravel-health-endpoints.php', 'laravel-health-endpoints');

        $this->app->singleton(Settings::class);
        $this->app->singleton(HealthCheckRegistry::class);
        $this->app->singleton(StateTracker::class);
        $this->app->singleton(HealthRunner::class);
        $this->app->singleton(ResultCache::class);
        $this->app->singleton(Heartbeats::class);

        $this->callAfterResolving(Schedule::class, $this->registerHeartbeats(...));
    }

    private function registerHeartbeats(Schedule $schedule): void
    {
        $settings = $this->app->make(Settings::class);

        if ($settings->checkEnabled('scheduler.enabled')) {
            $schedule->call([Heartbeats::class, 'touchScheduler'])
                ->everyMinute()
                ->name('lhe:scheduler-heartbeat');
        }

        if (! $settings->checkEnabled('queue.enabled')) {
            return;
        }

        $scheduled = [];

        foreach ((new QueueCheck)->targets() as $target) {
            [$connection, $queue] = QueueCheck::parseTarget($target);
            $driver = QueueCheck::driver($connection);
            $normalized = $connection.':'.$queue;

            // "redis" and "redis:default" share one heartbeat, so they share one scheduled job.
            if ($driver === null || $driver === 'sync' || $driver === 'null' || isset($scheduled[$normalized])) {
                continue;
            }

            $scheduled[$normalized] = true;

            $schedule->job(new QueueHeartbeatJob($normalized), $queue, $connection)
                ->everyMinute()
                ->name('lhe:queue-heartbeat:'.$normalized);
        }
    }

    public function boot(): void
    {
        // Registered unconditionally; the middleware reads config per request and no-ops when disabled.
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', InjectServiceMarkers::class);

        // The HTTP kernel re-syncs its own groups to the router, which would drop the push above; register there too.
        $this->callAfterResolving(Kernel::class, function (Kernel $kernel): void {
            if ($kernel instanceof FoundationKernel) {
                $kernel->appendMiddlewareToGroup('web', InjectServiceMarkers::class);
            }
        });

        Blade::directive('lheHeaderMarker', fn (): string => "<?php echo config('laravel-health-endpoints.markers.header'); ?>");
        Blade::directive('lheFooterMarker', fn (): string => "<?php echo config('laravel-health-endpoints.markers.footer'); ?>");

        // After every provider has booted, so checks registered in app providers get their custom routes.
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeHealthCheckCommand::class,
                ListHealthRoutesCommand::class,
                RunHealthChecksCommand::class,
                GenerateTokenCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../config/laravel-health-endpoints.php' => config_path('laravel-health-endpoints.php'),
            ], 'laravel-health-endpoints-config');

            $this->publishes([
                __DIR__.'/../../stubs' => base_path('stubs/lhe'),
            ], 'laravel-health-endpoints-stubs');
        }
    }
}
