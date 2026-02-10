<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

enum ScheduleOutcome: string
{
    case Succeeded          = 'succeeded';
    case Queued             = 'queued';
    case Failed             = 'failed';
    case SkippedOverlapping = 'skipped_overlapping';
    case SkippedOneServer   = 'skipped_one_server';
}
