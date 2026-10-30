<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use LEXO\LaravelHealthEndpoints\Checks\MigrationsCheck;

beforeEach(function () {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    app('db')->purge('testing');
    app()->forgetInstance('migration.repository');
    app()->forgetInstance('migrator');
});

function lheMigrationsRun()
{
    return (new MigrationsCheck)->run();
}

it('fails when the migrations table is missing', function () {
    $r = lheMigrationsRun();
    expect($r->isOk())->toBeFalse()->and($r->message)->toBe('Migrations table not found');
});

it('passes when everything ran', function () {
    Artisan::call('migrate:install');
    app('migrator')->path(__DIR__.'/../../Fixtures/migrations');
    app('migrator')->getRepository()->log('2026_01_01_000000_create_lhe_fixture_table', 1);

    $r = lheMigrationsRun();
    expect($r->isOk())->toBeTrue()->and($r->meta)->toBe(['pending' => 0]);
});

it('fails with the pending migration listed', function () {
    Artisan::call('migrate:install');
    app('migrator')->path(__DIR__.'/../../Fixtures/migrations');

    $r = lheMigrationsRun();
    expect($r->isOk())->toBeFalse()
        ->and($r->message)->toBe('1 pending migration(s): 2026_01_01_000000_create_lhe_fixture_table')
        ->and($r->meta)->toBe(['pending' => 1]);
});

it('truncates after three names', function () {
    Artisan::call('migrate:install');
    $dir = sys_get_temp_dir().'/lhe_mig_'.uniqid();
    mkdir($dir);
    foreach (['a', 'b', 'c', 'd'] as $i => $n) {
        file_put_contents("$dir/2026_01_0{$i}_000000_$n.php", '<?php return null;');
    }
    app('migrator')->path($dir);

    $r = lheMigrationsRun();
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);

    expect($r->message)->toBe('4 pending migration(s): 2026_01_00_000000_a, 2026_01_01_000000_b, 2026_01_02_000000_c, …')
        ->and($r->meta)->toBe(['pending' => 4]);
});
