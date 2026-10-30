<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Settings;

final class StorageCheck extends TargetedCheck
{
    private const DIRECTORY = '.lhe-health/';

    public function targets(): array
    {
        $configured = app(Settings::class)->list('storage.disks');

        if ($configured !== []) {
            return $configured;
        }

        $default = config('filesystems.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    public function runTarget(string $target): CheckResult
    {
        $disks = config('filesystems.disks');

        if (! is_array($disks) || ! is_array($disks[$target] ?? null)) {
            return CheckResult::fail('Disk not configured');
        }

        $path = self::DIRECTORY.Str::random(32).'.txt';
        $value = Str::random(32);
        $disk = Storage::disk($target);

        try {
            if ($disk->put($path, $value) === false) {
                return CheckResult::fail('Write failed');
            }

            if ($disk->get($path) !== $value) {
                return CheckResult::fail('Read-back mismatch');
            }

            return CheckResult::ok();
        } finally {
            // Only the file is removed; the shared directory stays to avoid racing concurrent probes.
            $disk->delete($path);
        }
    }
}
