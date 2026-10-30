<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function lheToken(array $args = []): string
{
    Artisan::call('lhe:token', $args);

    return trim(Artisan::output());
}

it('prints a 64 char hex token that differs per run', function () {
    $a = lheToken();
    $b = lheToken();

    expect($a)->toMatch('/^[a-f0-9]{64}$/')->and($b)->toMatch('/^[a-f0-9]{64}$/')->and($a)->not->toBe($b);
});

it('prints an env line with --show-env', function () {
    expect(lheToken(['--show-env' => true]))->toMatch('/^LHE_TOKEN=[a-f0-9]{64}$/');
});

it('does not touch the env file', function () {
    $env = base_path('.env');
    $before = file_exists($env) ? file_get_contents($env) : null;

    lheToken();

    expect(file_exists($env) ? file_get_contents($env) : null)->toBe($before);
});
