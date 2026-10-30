<?php

declare(strict_types=1);

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
