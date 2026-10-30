<?php

declare(strict_types=1);

use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;
use LEXO\LaravelHealthEndpoints\Support\Status;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\DiskSpaceCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\NamedFixtureCheck;
use LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks\TwoTargetFixture;

it('derives kebab names', fn () => expect((new DiskSpaceCheck)->name())->toBe('disk-space'));
it('allows name override', fn () => expect((new NamedFixtureCheck)->name())->toBe('custom-name'));
it('targeted run fails if any target fails', fn () => expect((new TwoTargetFixture(['a' => true, 'b' => false]))->run())
    ->status->toBe(Status::Fail)->message->toBe('1 of 2 targets failed'));
it('targeted run is ok when all pass', fn () => expect((new TwoTargetFixture(['a' => true, 'b' => true]))->run())
    ->status->toBe(Status::Ok));

it('targeted run fails with no targets', fn () => expect((new TwoTargetFixture([]))->run())
    ->status->toBe(Status::Fail)->message->toBe('No targets configured'));

it('targeted run counts throwing targets as failed', function () {
    $check = new class extends TargetedCheck
    {
        public function targets(): array
        {
            return ['a', 'b'];
        }

        public function runTarget(string $target): CheckResult
        {
            return $target === 'a' ? throw new RuntimeException('boom') : CheckResult::ok();
        }
    };
    expect($check->run())->status->toBe(Status::Fail)->message->toBe('1 of 2 targets failed');
});
