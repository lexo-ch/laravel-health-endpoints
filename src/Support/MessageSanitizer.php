<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Throwable;

final class MessageSanitizer
{
    private const MAX_LENGTH = 255;

    public static function sanitize(string $message): string
    {
        $line = preg_split('/\R/', $message, 2);
        $message = $line === false ? '' : $line[0];

        $message = preg_replace('#([a-z][a-z0-9+.\-]*://)[^/\s@]*@#i', '$1***@', $message) ?? '';
        $message = preg_replace('/\b(password|passwd|pwd|secret|token|key)=[^;&\s]*/i', '$1=***', $message) ?? '';
        $message = preg_replace('#(?:[A-Za-z]:)?[\\\\/](?:[^\s\\\\/:]+[\\\\/])*[^\s\\\\/:]+\.php(?::\d+)?#', '[path]', $message) ?? '';

        return mb_strimwidth(trim($message), 0, self::MAX_LENGTH, '');
    }

    public static function fromThrowable(Throwable $e): string
    {
        $raw = $e->getMessage();

        $map = [
            '/connection refused|no such host|could not translate host|getaddrinfo/i' => 'Connection refused',
            '/access denied|authentication failed|noauth|wrongpass/i' => 'Authentication failed',
            '/timed out|timeout/i' => 'Timeout',
            '/unknown database|does not exist/i' => 'Database not found',
        ];

        foreach ($map as $pattern => $safe) {
            if (preg_match($pattern, $raw) === 1) {
                return $safe;
            }
        }

        $clean = self::sanitize($raw);

        return $clean === '' ? 'Check failed' : $clean;
    }
}
