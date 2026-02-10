<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use DateTimeInterface;

final class GroupTask
{
    /** @var callable(DateTimeInterface): mixed */
    private $handler;

    /**
     * @param callable(DateTimeInterface): mixed $handler
     */
    public function __construct(
        callable $handler,
        private readonly ?string $name = null,
        private readonly ?string $handlerId = null,
        private readonly ?string $handlerClass = null,
    ) {
        $this->handler = $handler;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function handlerId(): ?string
    {
        return $this->handlerId;
    }

    public function handlerClass(): ?string
    {
        return $this->handlerClass;
    }

    public function run(DateTimeInterface $time): mixed
    {
        return ($this->handler)($time);
    }
}
