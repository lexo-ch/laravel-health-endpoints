<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Throwable;

final class MessageSanitizer
{
    private const MAX_LENGTH = 255;

    public static function sanitize(string $message): string
    {
        $message = mb_scrub($message);
        $parts = preg_split('/\R/u', $message, 2);
        $message = $parts === false ? '' : $parts[0];

        // scheme://user:pass@host (password may itself contain @ or /): greedy up to the last @ in the token
        $message = preg_replace('#([a-z][a-z0-9+.\-]*://)\S*@#iu', '$1***@', $message) ?? '';
        // password=x, DB_PASSWORD="a b", api_key: x, access_token='x'
        $message = preg_replace(
            '/([\w.\-]*(?:password|passwd|pwd|secret|token|key))(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^;&\s]*)/iu',
            '$1$2***',
            $message,
        ) ?? '';
        // Windows absolute paths, then Unix absolute paths with at least two segments (URLs are left alone)
        $message = preg_replace('#(?<![\w])[A-Za-z]:[\\\\/][^\s:\'",;]*(?::\d+)?#u', '[path]', $message) ?? '';
        $message = preg_replace('#(?<![\w.:/\-])(?:/[^\s/:\'",;()]+){2,}(?::\d+)?#u', '[path]', $message) ?? '';

        return mb_substr(trim($message), 0, self::MAX_LENGTH);
    }

    public static function fromThrowable(Throwable $e): string
    {
        $raw = $e->getMessage();

        $map = [
            '/connection refused|no such host|could not translate host|getaddrinfo/i' => 'Connection refused',
            '/access denied|authentication failed|noauth|wrongpass/i' => 'Authentication failed',
            '/timed out|timeout/i' => 'Timeout',
            '/unknown database|database "?[^"]*"? does not exist/i' => 'Database not found',
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
