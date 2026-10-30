<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class DebugModeCheck extends Check
{
    protected ?string $name = 'debug-mode';

    public function run(): CheckResult
    {
        $debug = (bool) config('app.debug');
        $forbidden = app(Settings::class)->list('debug_mode.forbidden_environments');

        if ($debug && $forbidden !== [] && app()->environment($forbidden)) {
            return CheckResult::fail('APP_DEBUG is enabled in '.app()->environment(), ['debug' => true]);
        }

        return CheckResult::ok(meta: ['debug' => $debug]);
    }
}
