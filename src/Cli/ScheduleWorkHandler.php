<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Cli;

use DateTimeZone;
use Exception;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function ctype_digit;
use function is_int;
use function is_string;

final readonly class ScheduleWorkHandler implements HandlerInterface
{
    public function __construct(
        private ScheduleWorker $worker,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $interval = $this->intOption($runner->request()->option('interval', 60));
        $maxRuns  = $this->intOption($runner->request()->option('max-runs', 0));

        if ($interval === null || $interval < 1 || $maxRuns === null || $maxRuns < 0) {
            $runner->io()->writeln('Некорректные параметры worker-а.', 'error');

            return Response::INVALID_INPUT;
        }

        try {
            $timezone = $this->timezone($runner->request()->option('timezone'));
        } catch (Exception) {
            $runner->io()->writeln('Некорректные параметры времени или часового пояса.', 'error');

            return Response::INVALID_INPUT;
        }

        $runner->io()->writeln('Scheduler worker запущен. Интервал: ' . $interval . ' сек.');
        $this->worker->run($runner, $timezone, $interval, $maxRuns);
        $runner->io()->writeln('Scheduler worker остановлен.');

        return Response::SUCCESS;
    }

    private function timezone(mixed $value): ?DateTimeZone
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeZone($value);
    }

    private function intOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
