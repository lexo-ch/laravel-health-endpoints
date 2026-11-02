<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Tests\Fixtures\Checks;

use LEXO\LaravelHealthEndpoints\Checks\TargetedCheck;
use LEXO\LaravelHealthEndpoints\Support\CheckResult;

final class TwoTargetFixture extends TargetedCheck
{
    /**
     * @param  array<string, bool>  $targets  target => passes
     */
    public function __construct(private readonly array $targets = ['a' => true, 'b' => true]) {}

    public function targets(): array
    {
        return array_map(strval(...), array_keys($this->targets));
    }

    public function runTarget(string $target): CheckResult
    {
        return ($this->targets[$target] ?? false)
            ? CheckResult::ok()
            : CheckResult::fail('Target failed');
    }
}
