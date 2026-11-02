<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;

$root = dirname(__DIR__, 2);

it('documents every env var', function () use ($root) {
    preg_match_all("/env\\('(LHE_[A-Z_]+)'/", (string) file_get_contents($root.'/config/laravel-health-endpoints.php'), $m);
    $readme = (string) file_get_contents($root.'/README.md');

    expect($m[1])->not->toBeEmpty();

    foreach (array_unique($m[1]) as $var) {
        expect($readme)->toContain("`{$var}`");
    }
});

it('ships the boost guideline', function () use ($root) {
    expect(file_exists($root.'/resources/boost/guidelines/core.blade.php'))->toBeTrue();
});

it('lists every reserved name in the README and the Boost guideline', function () use ($root) {
    $readme = (string) file_get_contents($root.'/README.md');
    $guideline = (string) file_get_contents($root.'/resources/boost/guidelines/core.blade.php');

    foreach (HealthCheckRegistry::RESERVED as $name) {
        expect($readme)->toContain("`{$name}`")
            ->and($guideline)->toContain($name);
    }
});

it('ships a security policy and a changelog', function () use ($root) {
    expect((string) @file_get_contents($root.'/SECURITY.md'))->toContain('Security Advisories')
        ->and(file_exists($root.'/CHANGELOG.md'))->toBeTrue();
});

it('keeps the Boost guideline free of raw PHP tags and Blade directives outside @verbatim', function () use ($root) {
    $guideline = trim((string) file_get_contents($root.'/resources/boost/guidelines/core.blade.php'));

    // Blade executes raw PHP tags even inside @verbatim; directives like @lheHeaderMarker must stay verbatim.
    expect($guideline)->not->toContain('<?php')
        ->and($guideline)->toStartWith('@verbatim')
        ->and($guideline)->toEndWith('@endverbatim');
});

it('keeps development files out of the dist archive', function () use ($root) {
    $attributes = (string) @file_get_contents($root.'/.gitattributes');
    preg_match_all('/^(\S+)\s+export-ignore\s*$/m', $attributes, $m);

    expect($m[1])->toEqualCanonicalizing([
        '/.github', '/.superpowers', '/docs', '/tests', '/.gitattributes', '/.gitignore', '/.releaserc',
        '/package.json', '/package-lock.json', '/phpstan.neon', '/phpunit.xml', '/pint.json',
    ]);

    foreach (['src', 'config', 'stubs', 'resources', 'composer.json', 'README.md', 'LICENSE'] as $shipped) {
        expect($m[1])->not->toContain('/'.$shipped);
    }
});
