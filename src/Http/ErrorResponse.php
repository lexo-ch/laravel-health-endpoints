<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Http;

use Illuminate\Http\JsonResponse;
use LEXO\LaravelHealthEndpoints\Support\Json;

/**
 * The package's fixed JSON error body: {"status":"fail","message":"..."}. Messages are constants, never request data.
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
        return Json::response(['status' => 'fail', 'message' => $message], $status, $headers);
    }
}
