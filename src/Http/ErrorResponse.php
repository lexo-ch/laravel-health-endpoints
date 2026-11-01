<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http;

use Illuminate\Http\JsonResponse;
use LEXO\LaravelHealthEndpoints\Support\Json;

/**
 * The package's fixed JSON error body: {"status":"error","message":"..."} for rejected requests (4xx), and
 * {"status":"fail","message":"..."} for 5xx, where the app really is unhealthy. "fail" is reserved for health
 * results, so a bad token or URL never looks like an outage. Messages are constants, never request data.
 */
final class ErrorResponse
{
    public const UNAUTHORIZED = 'Unauthorized';

    public const FORBIDDEN = 'Forbidden';

    public const NOT_FOUND = 'Not found';

    public const METHOD_NOT_ALLOWED = 'Method not allowed';

    public const TOO_MANY_REQUESTS = 'Too many requests';

    public const ERROR = 'Health check error';

    /**
     * @param  array<string, string>  $headers
     */
    public static function make(string $message, int $status, array $headers = []): JsonResponse
    {
        return Json::response(['status' => $status >= 500 ? 'fail' : 'error', 'message' => $message], $status, $headers);
    }
}
