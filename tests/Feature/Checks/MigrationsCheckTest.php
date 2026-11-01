<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LEXO\LaravelHealthEndpoints\Checks\MigrationsCheck;

// The check reads through a separate probe connection, so the database must be a file (":memory:" is per connection).
beforeEach(function () {
    $this->lheDb = tempnam(sys_get_temp_dir(), 'lhe_mig_db_');
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => $this->lheDb, 'prefix' => ''],
    ]);
    app('db')->purge('testing');
    app()->forgetInstance('migration.repository');
    app()->forgetInstance('migrator');
});

afterEach(function () {
    app('db')->purge('testing');
    @unlink($this->lheDb);
});

function lheMigrationsRun()
{
    return (new MigrationsCheck)->run();
}

function lheAssertNoMigrationProbeResidue(): void
{
    $isProbe = static fn (string $key): bool => str_starts_with($key, 'lhe_probe_');

    expect(array_filter(array_keys(config('database.connections')), $isProbe))->toBe([])
        ->and(array_filter(array_keys(DB::getConnections()), $isProbe))->toBe([]);
}

it('fails when the migrations table is missing', function () {
    $r = lheMigrationsRun();
    expect($r->isOk())->toBeFalse()->and($r->message)->toBe('Migrations table not found');
    lheAssertNoMigrationProbeResidue();
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

    try {
        foreach (['a', 'b', 'c', 'd'] as $i => $n) {
            file_put_contents("$dir/2026_01_0{$i}_000000_$n.php", '<?php return null;');
        }
        app('migrator')->path($dir);

        $r = lheMigrationsRun();
    } finally {
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    expect($r->message)->toBe('4 pending migration(s): 2026_01_00_000000_a, 2026_01_01_000000_b, 2026_01_02_000000_c, …')
        ->and($r->meta)->toBe(['pending' => 4]);
});

it('reads through a throwaway probe connection and never touches the app connection', function () {
    Artisan::call('migrate:install');
    $app = DB::connection('testing');
    $pdo = $app->getPdo();
    $connections = [];
    DB::listen(function (QueryExecuted $e) use (&$connections) {
        $connections[] = $e->connectionName;
    });

    expect(lheMigrationsRun()->isOk())->toBeTrue()
        ->and($connections)->not->toBeEmpty()
        ->and(array_filter($connections, fn (string $c): bool => ! str_starts_with($c, 'lhe_probe_testing_')))->toBe([])
        ->and(DB::connection('testing')->getPdo())->toBe($pdo)
        ->and(DB::getDefaultConnection())->toBe('testing');
    lheAssertNoMigrationProbeResidue();
});

it('applies the configured timeout to the probe connection', function () {
    config([
        'laravel-health-endpoints.timeout' => 6,
        'database.default' => 'remote',
        'database.connections.remote' => ['driver' => 'mysql', 'host' => 'db.invalid', 'database' => 'app', 'prefix' => ''],
    ]);
    app()->forgetInstance('migrator');
    $captured = lheCaptureMysqlProbeConfig();

    $r = lheMigrationsRun();

    expect($r->message)->toBe('Migrations table not found')
        ->and($captured->config['name'])->toStartWith('lhe_probe_remote_')
        ->and($captured->config['options'][PDO::ATTR_TIMEOUT])->toBe(6);
    lheAssertNoMigrationProbeResidue();
});

it('fails when the migrator connection is not configured', function () {
    config(['database.default' => 'ghost']);
    app()->forgetInstance('migrator');

    expect(lheMigrationsRun()->message)->toBe('Connection not configured');
});

it('reads an in-memory sqlite database through the app connection', function (string $database) {
    config(['database.connections.memory' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'database.default' => 'memory']);
    app()->forgetInstance('migration.repository');
    app()->forgetInstance('migrator');

    expect(lheMigrationsRun()->message)->toBe('Migrations table not found');

    Artisan::call('migrate:install');
    app('migrator')->path(__DIR__.'/../../Fixtures/migrations');
    app('migrator')->getRepository()->log('2026_01_01_000000_create_lhe_fixture_table', 1);

    $r = lheMigrationsRun();
    expect($r->isOk())->toBeTrue()->and($r->meta)->toBe(['pending' => 0]);
    lheAssertNoMigrationProbeResidue();

    app('db')->purge('memory');
})->with([':memory:', 'file:lhe?mode=memory&cache=shared']);
