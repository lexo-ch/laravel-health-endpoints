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

it('redacts widened secret shapes', fn (string $in, string $out) => expect(MessageSanitizer::sanitize($in))->toBe($out))->with([
    ['api_key=abc123 failed', 'api_key=*** failed'],
    ['DB_PASSWORD=s3cret;x=1', 'DB_PASSWORD=***;x=1'],
    ['access_token=abc&x=1', 'access_token=***&x=1'],
    ['password="a b" next', 'password=*** next'],
    ["password='a b' next", 'password=*** next'],
    ['password: hunter2', 'password: ***'],
    ['redis://u:p@ss@host:6379 refused', 'redis://***@host:6379 refused'],
    ['redis://u:p/ss@host:6379', 'redis://***@host:6379'],
]);

it('redacts any absolute path but keeps URLs', fn (string $in, string $out) => expect(MessageSanitizer::sanitize($in))->toBe($out))->with([
    ['connect to /var/run/mysqld/mysqld.sock failed', 'connect to [path] failed'],
    ['open C:\\Windows\\temp\\x.log failed', 'open [path] failed'],
    ['GET https://example.com/health/check failed', 'GET https://example.com/health/check failed'],
    ['read and/or write', 'read and/or write'],
]);

it('scrubs invalid utf-8', function () {
    $out = MessageSanitizer::sanitize("bad \xB1\x31 bytes");
    expect(mb_check_encoding($out, 'UTF-8'))->toBeTrue()->and(json_encode($out))->not->toBeFalse();
});

it('truncates multibyte to exactly 255 chars', function () {
    $out = MessageSanitizer::sanitize(str_repeat('é', 400));
    expect(mb_strlen($out))->toBe(255)->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue();
});

it('does not map generic does-not-exist to database', function () {
    expect(MessageSanitizer::fromThrowable(new RuntimeException('Table x does not exist')))->toBe('Table x does not exist')
        ->and(MessageSanitizer::fromThrowable(new RuntimeException('database "app" does not exist')))->toBe('Database not found');
});
