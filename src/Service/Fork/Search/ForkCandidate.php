<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Mail\MessageThread;

/** FORK (FORK.md): one search result as the panels above the list read it. See ForkSearchCandidates. */
final readonly class ForkCandidate
{
    public function __construct(
        public MessageThread $thread,
        public ?string       $sender,
        /** The fragment the search matched, as plain text. */
        public ?string       $snippet,
        /** The reranker's 0–1, or null when nothing reranked. */
        public ?float        $score = null,
    ) {
    }

    public function withScore(float $score): self
    {
        return new self($this->thread, $this->sender, $this->snippet, $score);
    }

    /** What the reranker reads: who, what, and the words that matched. */
    public function document(): string
    {
        return trim(implode("\n", array_filter([
            null === $this->sender ? null : 'From: ' . $this->sender,
            null === $this->thread->lastMessageAt ? null : 'Date: ' . $this->thread->lastMessageAt->format('Y-m-d'),
            'Subject: ' . trim((string) $this->thread->subject),
            $this->snippet,
        ], static fn (?string $part): bool => null !== $part && '' !== $part)));
    }
}
