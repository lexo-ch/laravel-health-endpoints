<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Http\ErrorResponse;

it('marks rejected requests as error, never as a failed health check', function (string $message, int $code) {
    expect(ErrorResponse::make($message, $code)->getData(true))->toBe(['status' => 'error', 'message' => $message]);
})->with([
    [ErrorResponse::UNAUTHORIZED, 401],
    [ErrorResponse::FORBIDDEN, 403],
    [ErrorResponse::NOT_FOUND, 404],
    [ErrorResponse::METHOD_NOT_ALLOWED, 405],
    [ErrorResponse::TOO_MANY_REQUESTS, 429],
]);

it('keeps fail for server errors', function () {
    expect(ErrorResponse::make(ErrorResponse::ERROR, 503)->getData(true))
        ->toBe(['status' => 'fail', 'message' => ErrorResponse::ERROR]);
});
