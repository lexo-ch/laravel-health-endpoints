<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Checks;

use Illuminate\Support\Str;
use LEXO\LaravelHealthEndpoints\Contracts\HealthCheck;

abstract class Check implements HealthCheck
{
    protected ?string $name = null;

    public function name(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        $base = class_basename(static::class);

        return Str::kebab(Str::replaceEnd('Check', '', $base));
    }
}
