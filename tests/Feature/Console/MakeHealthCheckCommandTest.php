<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;

beforeEach(function () {
    $this->dir = app_path('HealthChecks');
    File::deleteDirectory($this->dir);
    File::delete(base_path('stubs/lhe/health-check.stub'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory(base_path('stubs/lhe'));
});

it('generates a check class and does not double the Check suffix', function (string $input) {
    $this->artisan('make:health-check', ['name' => $input])->assertSuccessful();

    $file = $this->dir.'/DiskSpaceCheck.php';
    expect($file)->toBeFile();
    $code = file_get_contents($file);
    expect($code)
        ->toContain('namespace App\HealthChecks;')
        ->toContain('use LEXO\LaravelHealthEndpoints\Checks\Check;')
        ->toContain('use LEXO\LaravelHealthEndpoints\Support\CheckResult;')
        ->toContain('class DiskSpaceCheck extends Check')
        ->toContain('php artisan lhe:run disk-space')
        ->toContain('[a-z0-9-]')
        ->toContain('LHE_TIMEOUT')
        ->toContain('Never put secrets')
        ->not->toContain('{{');
    expect(file_exists($this->dir.'/DiskSpaceCheckCheck.php'))->toBeFalse();
})->with(['DiskSpace', 'DiskSpaceCheck']);

it('generates a targeted check with --targets', function () {
    $this->artisan('make:health-check', ['name' => 'Mail', '--targets' => true])->assertSuccessful();

    $code = file_get_contents($this->dir.'/MailCheck.php');
    expect($code)->toContain('class MailCheck extends TargetedCheck')
        ->toContain('public function targets(): array')
        ->toContain('public function runTarget(string $target): CheckResult')
        ->toContain('php artisan lhe:run mail');
});

it('generates syntactically valid php', function (bool $targets) {
    $this->artisan('make:health-check', ['name' => 'Lint', '--targets' => $targets])->assertSuccessful();

    exec('php -l '.escapeshellarg($this->dir.'/LintCheck.php').' 2>&1', $out, $code);
    expect($code)->toBe(0);
})->with([false, true]);

it('does not overwrite an existing file without --force', function () {
    $this->artisan('make:health-check', ['name' => 'Keep'])->assertSuccessful();
    file_put_contents($this->dir.'/KeepCheck.php', 'MINE');

    $this->artisan('make:health-check', ['name' => 'Keep'])->assertFailed();
    expect(file_get_contents($this->dir.'/KeepCheck.php'))->toBe('MINE');

    $this->artisan('make:health-check', ['name' => 'Keep', '--force' => true])->assertSuccessful();
    expect(file_get_contents($this->dir.'/KeepCheck.php'))->toContain('class KeepCheck');
});

it('rejects invalid, traversing, bare and reserved names', function (string $name) {
    $before = File::allFiles(app_path());
    $this->artisan('make:health-check', ['name' => $name])->assertFailed();

    expect(File::exists($this->dir))->toBeFalse()
        ->and(File::exists(base_path('Evil.php')))->toBeFalse()
        ->and(File::exists(dirname(base_path()).'/Evil.php'))->toBeFalse()
        ->and(File::allFiles(app_path()))->toEqual($before);
})->with(['1bad', 'bad name', 'Bad-Name', '../../Evil', 'Sub/Foo', 'Foo\\Bar', 'Foo.Bar', 'Foo_Bar', 'foo', 'Check', 'Ping', 'PingCheck', 'DebugMode', 'Redis']);

it('uses a published stub when present', function () {
    File::ensureDirectoryExists(base_path('stubs/lhe'));
    file_put_contents(base_path('stubs/lhe/health-check.stub'), "<?php\nnamespace {{ namespace }};\nclass {{ class }} {} // PUBLISHED {{ name }}\n");

    $this->artisan('make:health-check', ['name' => 'Custom'])->assertSuccessful();

    expect(file_get_contents($this->dir.'/CustomCheck.php'))->toContain('// PUBLISHED custom');
});

it('discovers the generated check', function () {
    $this->artisan('make:health-check', ['name' => 'Gen'])->assertSuccessful();

    config([
        'laravel-health-endpoints.discover.enabled' => true,
        'laravel-health-endpoints.discover.path' => $this->dir,
        'laravel-health-endpoints.discover.namespace' => 'App\\HealthChecks',
    ]);

    require_once $this->dir.'/GenCheck.php';

    $registry = app()->make(HealthCheckRegistry::class);
    expect($registry->find('gen'))->not->toBeNull();
});
