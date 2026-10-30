<?php

declare(strict_types=1);

// Pest's toUse() does not reliably catch global function calls (verified: an env() call in src/ did not fail it),
// so scan the sources directly.
it('never calls env() or debug helpers in src', function (string $function) {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/(?<![\w>:$])'.$function.'\s*\(/', $code) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
})->with(['env', 'dd', 'dump', 'var_dump', 'ray']);

it('detects a forbidden call (guard against a vacuous scan)', function () {
    expect(preg_match('/(?<![\w>:$])env\s*\(/', '<?php return env("A");'))->toBe(1);
});
