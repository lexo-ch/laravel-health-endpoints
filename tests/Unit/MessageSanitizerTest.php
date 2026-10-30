<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Support\MessageSanitizer;

it('strips secrets', fn (string $in, string $out) => expect(MessageSanitizer::sanitize($in))->toBe($out))->with([
    ['redis://user:p4ss@10.0.0.1:6379 refused', 'redis://***@10.0.0.1:6379 refused'],
    ['host=db;password=hunter2;port=5432', 'host=db;password=***;port=5432'],
    ['failed in /var/www/app/vendor/x/Foo.php:12', 'failed in [path]'],
    ['failed in C:\\www\\app\\Foo.php:12', 'failed in [path]'],
    ["line1\nline2", 'line1'],
]);

it('truncates to 255 chars', fn () => expect(strlen(MessageSanitizer::sanitize(str_repeat('a', 400))))->toBe(255));

it('maps known failures', fn (string $raw, string $safe) => expect(MessageSanitizer::fromThrowable(new PDOException($raw)))->toBe($safe))->with([
    ['SQLSTATE[HY000] [2002] Connection refused', 'Connection refused'],
    ["SQLSTATE[HY000] [1045] Access denied for user 'app'@'10.0.0.5'", 'Authentication failed'],
    ['FATAL: password authentication failed for user "app"', 'Authentication failed'],
    ['Operation timed out', 'Timeout'],
    ["SQLSTATE[HY000] [1049] Unknown database 'x'", 'Database not found'],
]);

it('falls back to sanitized message and generic text', function () {
    expect(MessageSanitizer::fromThrowable(new RuntimeException('bad password=abc in /a/b.php:3')))->toBe('bad password=*** in [path]')
        ->and(MessageSanitizer::fromThrowable(new RuntimeException('')))->toBe('Check failed');
});
