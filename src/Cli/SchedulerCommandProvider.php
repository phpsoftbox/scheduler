<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Cli;

use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Command\OptionDefinition;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class SchedulerCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'schedule:run',
            description: 'Запустить планировщик задач',
            signature: [
                new OptionDefinition(
                    name: 'time',
                    short: 't',
                    description: 'Время выполнения (например, "2024-01-01 12:00:00")',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'timezone',
                    short: 'z',
                    description: 'Часовой пояс (например, "Europe/Moscow")',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: ScheduleRunHandler::class,
        ));

        $registry->register(Command::define(
            name: 'schedule:work',
            description: 'Запустить планировщик задач в режиме daemon',
            signature: [
                new OptionDefinition(
                    name: 'interval',
                    short: 'i',
                    description: 'Интервал между проверками расписания в секундах',
                    required: false,
                    default: 60,
                    type: 'int',
                ),
                new OptionDefinition(
                    name: 'timezone',
                    short: 'z',
                    description: 'Часовой пояс (например, "Europe/Moscow")',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'max-runs',
                    short: null,
                    description: 'Максимальное число итераций, 0 — без ограничения',
                    required: false,
                    default: 0,
                    type: 'int',
                ),
            ],
            handler: ScheduleWorkHandler::class,
        ));
    }
}
