<?php

declare(strict_types=1);

arch('package never calls env()')
    ->expect('LEXO\LaravelHealthEndpoints')
    ->not->toUse(['env']);

arch('no debug helpers')
    ->expect('LEXO\LaravelHealthEndpoints')
    ->not->toUse(['dd', 'dump', 'var_dump']);
