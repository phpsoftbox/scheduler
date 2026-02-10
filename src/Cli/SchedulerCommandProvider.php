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
    }
}
