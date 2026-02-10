<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

final class FakeRunner implements RunnerInterface
{
    /** @var list<array{0: string, 1: array}> */
    public array $subCommands = [];

    public function __construct(
        private readonly Request $request,
        private readonly IoInterface $io,
    ) {
    }

    public function run(string $command, array $argv): Response
    {
        return new Response();
    }

    public function runSubCommand(string $command, array $argv): Response
    {
        $this->subCommands[] = [$command, $argv];

        return new Response();
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function io(): IoInterface
    {
        return $this->io;
    }
}
