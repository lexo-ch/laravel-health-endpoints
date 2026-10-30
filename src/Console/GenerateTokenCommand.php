<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'lhe:token', description: 'Generate a random health endpoint token (printed only, nothing is written)')]
final class GenerateTokenCommand extends Command
{
    protected $signature = 'lhe:token {--show-env : Print as LHE_TOKEN=<token>}';

    protected $description = 'Generate a random health endpoint token (printed only, nothing is written)';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));

        $this->line($this->option('show-env') ? 'LHE_TOKEN='.$token : $token);

        return self::SUCCESS;
    }
}
