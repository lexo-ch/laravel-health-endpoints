<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

use Illuminate\Http\JsonResponse;

/**
 * JSON encoding used by every endpoint and the --json console output: readable unicode and slashes, while keeping
 * Laravel's default HTML-safe escaping of <, >, &, ' and " inside string values. Invalid UTF-8 is replaced and
 * unencodable values (NAN, INF) become null instead of failing the whole response.
 */
final class Json
{
    public const FLAGS = JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * @param  array<mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function response(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers, self::FLAGS);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function pretty(array $data): string
    {
        return (string) json_encode($data, self::FLAGS | JSON_PRETTY_PRINT);
    }
}
