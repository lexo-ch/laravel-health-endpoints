<?php

declare(strict_types=1);

namespace LEXO\LaravelHealthEndpoints\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LEXO\LaravelHealthEndpoints\Exceptions\InvalidHealthCheckException;
use LEXO\LaravelHealthEndpoints\Exceptions\TargetNotFoundException;
use LEXO\LaravelHealthEndpoints\Registry\HealthCheckRegistry;
use LEXO\LaravelHealthEndpoints\Runner\HealthRunner;
use LEXO\LaravelHealthEndpoints\Support\CheckReport;
use LEXO\LaravelHealthEndpoints\Support\HealthReport;
use LEXO\LaravelHealthEndpoints\Support\Json;
use LEXO\LaravelHealthEndpoints\Support\Status;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'lhe:run', description: 'Run health checks from the CLI (bypasses the result cache)')]
final class RunHealthChecksCommand extends Command
{
    protected $signature = 'lhe:run {check? : Check name} {target? : Target of the check} {--json : Output the JSON report}';

    protected $description = 'Run health checks from the CLI (bypasses the result cache)';

    public function handle(HealthCheckRegistry $registry, HealthRunner $runner): int
    {
        $name = $this->argument('check');
        $target = $this->argument('target');

        try {
            $timestamp = CarbonImmutable::now();
            $start = hrtime(true);

            if (is_string($name)) {
                $check = $registry->find($name);

                if ($check === null) {
                    $this->components->error(sprintf('Unknown health check "%s". Available: %s', $name, implode(', ', array_keys($registry->all())) ?: 'none'));

                    return 2;
                }

                $reports = [$check->name() => $runner->runCheck($check, is_string($target) ? $target : null)];
                $report = new HealthReport($reports, $timestamp, (hrtime(true) - $start) / 1_000_000);
            } else {
                $report = $runner->runAll($registry->all());
            }
        } catch (TargetNotFoundException $e) {
            $this->components->error($e->getMessage());

            return 2;
        } catch (InvalidHealthCheckException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(Json::pretty($report->toJsonArray()));
        } else {
            $this->table(['Check', 'Target', 'Status', 'Duration (ms)', 'Message'], $this->rows($report));
        }

        return $report->status() === Status::Ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{string, string, string, int, string}>
     */
    private function rows(HealthReport $report): array
    {
        $rows = [];

        foreach ($report->checks as $check) {
            if ($check->targets === null) {
                $rows[] = $this->row($check, '-', $check);

                continue;
            }

            foreach ($check->targets as $target => $targetReport) {
                $rows[] = $this->row($check, (string) $target, $targetReport);
            }
        }

        return $rows;
    }

    /**
     * @return array{string, string, string, int, string}
     */
    private function row(CheckReport $check, string $target, CheckReport $source): array
    {
        return [$check->name, $target, $source->status->value, (int) round($source->durationMs), $source->message ?? ''];
    }
}
