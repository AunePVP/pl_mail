<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Mail\MessageThread;

/** FORK (FORK.md): what ForkSearchAnswer makes of a search. */
final readonly class ForkAnswer
{
    /**
     * @param list<MessageThread>                                        $important
     * @param list<array{title: string, threads: list<MessageThread>}> $groups
     */
    public function __construct(
        public string $text,
        public array  $important,
        public array  $groups,
    ) {
    }
}
