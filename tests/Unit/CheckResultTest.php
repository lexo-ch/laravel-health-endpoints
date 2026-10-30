<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Status;

it('builds ok results', function () {
    $r = CheckResult::ok('fine', ['v' => 1]);
    expect($r->isOk())->toBeTrue()->and($r->status)->toBe(Status::Ok)->and($r->message)->toBe('fine')->and($r->meta)->toBe(['v' => 1]);
});

it('builds fail results', function () {
    $r = CheckResult::fail('boom');
    expect($r->isOk())->toBeFalse()->and($r->status)->toBe(Status::Fail)->and($r->message)->toBe('boom')->and($r->meta)->toBe([]);
});
