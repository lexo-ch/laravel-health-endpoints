<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:health-check', description: 'Create a new health check class')]
final class MakeHealthCheckCommand extends GeneratorCommand
{
    protected $name = 'make:health-check';

    protected $description = 'Create a new health check class';

    protected $type = 'Health check';

    private bool $failed = false;

    public function handle(): ?bool
    {
        $result = $this->validName() ? parent::handle() : false;
        $this->failed = $result === false;

        return $result;
    }

    // GeneratorCommand returns false (exit code 0) on "already exists"/invalid input; surface that as a failure.
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = parent::execute($input, $output);

        return $this->failed ? self::FAILURE : $code;
    }

    protected function getNameInput(): string
    {
        $name = trim($this->rawName());

        return Str::endsWith($name, 'Check') ? $name : $name.'Check';
    }

    private function validName(): bool
    {
        $name = trim($this->rawName());

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error('The name must be StudlyCase (letters and digits, starting with a capital letter); no paths, dots or underscores.');

            return false;
        }

        $check = Str::kebab(Str::replaceEnd('Check', '', $name));

        if ($check === '') {
            $this->components->error('The name must be more than just "Check".');

            return false;
        }

        if (in_array($check, HealthCheckRegistry::RESERVED, true)) {
            $this->components->error(sprintf('The check name "%s" is reserved (%s). Choose another name.', $check, implode(', ', HealthCheckRegistry::RESERVED)));

            return false;
        }

        return true;
    }

    private function rawName(): string
    {
        $name = $this->argument('name');

        return is_string($name) ? $name : '';
    }

    protected function getStub(): string
    {
        $file = $this->option('targets') ? 'health-check.targets.stub' : 'health-check.stub';
        $published = base_path('stubs/lhe/'.$file);

        return file_exists($published) ? $published : __DIR__.'/../../stubs/'.$file;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\HealthChecks';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $check = Str::kebab(Str::replaceEnd('Check', '', class_basename($name)));

        return str_replace('{{ name }}', $check, $stub);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['targets', null, InputOption::VALUE_NONE, 'Generate a check with multiple targets'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if it already exists'],
        ];
    }
}
