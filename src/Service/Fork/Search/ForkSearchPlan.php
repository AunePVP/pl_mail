<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

/**
 * FORK (FORK.md): ForkSearchPlanner's reading of one chat message.
 */
final readonly class ForkSearchPlan
{
    /** Whether the days asked about are over: then they narrow the mail searched, not just the calendar. */
    public function isPast(\DateTimeImmutable $today): bool
    {
        return null !== $this->to && $this->to < $today->setTime(0, 0);
    }

    /**
     * @param list<string> $queries  searches as typed into the box, filters included; empty: search nothing new
     * @param string       $question the message as a question that stands on its own
     * @param ?\DateTimeImmutable $from first day the message asks about ("diese Woche": today), null when it names no time
     * @param ?\DateTimeImmutable $to   last day, included
     * @param bool               $oldest the message asks for the first or earliest ("das erste Mal", "seit wann")
     */
    public function __construct(
        public array               $queries,
        public string              $question,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public bool                $oldest = false,
    ) {
    }
}
