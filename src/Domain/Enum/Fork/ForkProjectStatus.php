<?php

declare(strict_types=1);

namespace App\Domain\Enum\Fork;

/**
 * FORK (FORK.md 27): what a person decided about a message and Projektakte.
 *
 * Two answers, because "not decided yet" is the absence of a row: a message
 * someone looked at and said has nothing to do with any project is a decision
 * Projektakte must keep, and one nobody has looked at is not.
 */
enum ForkProjectStatus: string
{
    /** Filed under one project. */
    case Assigned = 'assigned';

    /** Looked at, and about no project. */
    case None = 'none';
}
