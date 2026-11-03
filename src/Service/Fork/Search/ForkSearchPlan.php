<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

/**
 * FORK (FORK.md): ForkSearchPlanner's reading of one chat message.
 */
final readonly class ForkSearchPlan
{
    /**
     * @param list<string> $queries  searches as typed into the box, filters included; empty: search nothing new
     * @param string       $question the message as a question that stands on its own
     */
    public function __construct(
        public array  $queries,
        public string $question,
    ) {
    }
}
