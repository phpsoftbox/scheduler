<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Scheduler\ScheduleLoader;
use PhpSoftBox\Scheduler\Scheduler;

use function count;
use function is_string;

final class ScheduleRunHandler implements HandlerInterface
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleLoader $loader,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $timeOption     = $runner->request()->option('time');
        $timezoneOption = $runner->request()->option('timezone');

        try {
            $timezone = null;
            if (is_string($timezoneOption) && $timezoneOption !== '') {
                $timezone = new DateTimeZone($timezoneOption);
            }

            $time = is_string($timeOption) && $timeOption !== ''
                ? new DateTimeImmutable($timeOption, $timezone)
                : new DateTimeImmutable('now', $timezone);
        } catch (Exception $exception) {
            $runner->io()->writeln('Некорректные параметры времени или часового пояса.', 'error');

            return Response::INVALID_INPUT;
        }

        $this->loader->load($this->scheduler);
        $this->scheduler->setCommandRunner([$runner, 'runSubCommand']);
        $results = $this->scheduler->dispatch($time);

        $runner->io()->writeln('Выполнено задач: ' . count($results));

        return Response::SUCCESS;
    }
}
