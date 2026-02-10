<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Cli;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Scheduler\ScheduleLoader;
use PhpSoftBox\Scheduler\Scheduler;

use function constant;
use function count;
use function defined;
use function function_exists;
use function max;
use function pcntl_async_signals;
use function pcntl_signal;
use function sleep;
use function time;

final class ScheduleWorker
{
    private bool $stopRequested = false;

    /** @var Closure(): int */
    private Closure $timeProvider;

    /** @var Closure(int): void */
    private Closure $sleeper;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleLoader $loader,
        ?callable $timeProvider = null,
        ?callable $sleeper = null,
    ) {
        $this->timeProvider = $timeProvider !== null
            ? Closure::fromCallable($timeProvider)
            : static fn (): int => time();

        $this->sleeper = $sleeper !== null
            ? Closure::fromCallable($sleeper)
            : static function (int $seconds): void {
                sleep($seconds);
            };
    }

    public function run(
        RunnerInterface $runner,
        ?DateTimeZone $timezone = null,
        int $intervalSeconds = 60,
        int $maxRuns = 0,
    ): int {
        $this->stopRequested = false;
        $intervalSeconds     = max(1, $intervalSeconds);
        $maxRuns             = max(0, $maxRuns);
        $runs                = 0;

        $this->registerSignalHandlers();
        $this->loader->load($this->scheduler);
        $this->scheduler->setCommandRunner([$runner, 'runSubCommand']);

        while (!$this->stopRequested) {
            $time    = $this->currentTime($timezone);
            $results = $this->scheduler->dispatch($time);
            $runs++;

            $runner->io()->writeln(
                '[' . $time->format('Y-m-d H:i:s T') . '] Выполнено задач: ' . count($results),
            );

            if ($maxRuns > 0 && $runs >= $maxRuns) {
                break;
            }

            $this->sleepUntilNextTick($intervalSeconds);
        }

        return $runs;
    }

    private function currentTime(?DateTimeZone $timezone): DateTimeImmutable
    {
        $time = new DateTimeImmutable('@' . ($this->timeProvider)());

        return $timezone !== null ? $time->setTimezone($timezone) : $time;
    }

    private function sleepUntilNextTick(int $intervalSeconds): void
    {
        $now          = ($this->timeProvider)();
        $sleepSeconds = $intervalSeconds - ($now % $intervalSeconds);

        if ($sleepSeconds <= 0) {
            $sleepSeconds = $intervalSeconds;
        }

        ($this->sleeper)($sleepSeconds);
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        if (defined('SIGTERM')) {
            pcntl_signal(constant('SIGTERM'), function (): void {
                $this->stopRequested = true;
            });
        }

        if (defined('SIGINT')) {
            pcntl_signal(constant('SIGINT'), function (): void {
                $this->stopRequested = true;
            });
        }
    }
}
