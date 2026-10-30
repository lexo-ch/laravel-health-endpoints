<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Support;

enum Status: string
{
    case Ok = 'ok';
    case Fail = 'fail';
}
