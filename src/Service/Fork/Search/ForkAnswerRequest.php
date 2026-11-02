<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Mail\MessageThread;

/**
 * FORK (FORK.md): one search's question to the chat model, before it is asked.
 * See ForkSearchAnswer::prepare().
 */
final readonly class ForkAnswerRequest
{
    /**
     * @param string                                     $key      the cache key of this question's answer
     * @param array<int, MessageThread>                  $threads  the results it is given, by id
     * @param list<array{role: string, content: string}> $messages
     */
    public function __construct(
        public string $key,
        public array  $threads,
        public array  $messages,
    ) {
    }
}
