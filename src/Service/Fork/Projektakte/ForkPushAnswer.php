<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

/**
 * FORK (FORK.md 30): what Projektakte said to one push request, or that it
 * said nothing (status 0: no connection, timeout).
 *
 * The contract sorts the answers into three: taken (2xx), worth asking again
 * later (no answer, 5xx, and 401/403/429 — a token or a limit that someone can
 * fix without the mail changing), and never worth asking again (any other
 * 4xx: 400 malformed, 413 too large, 422 not assigned). 404 is the caller's to
 * read: for PUT …/zuordnung it means "send the whole mail".
 */
final readonly class ForkPushAnswer
{
    public function __construct(
        public int    $status,
        public string $detail = '',
    ) {
    }

    public function taken(): bool
    {
        return 200 <= $this->status && 300 > $this->status;
    }

    public function worthRetrying(): bool
    {
        return 0 === $this->status || 500 <= $this->status || in_array($this->status, [401, 403, 408, 429], true);
    }

    /** For the log and the settings: never the token, which is in a header. */
    public function describe(): string
    {
        $what = 0 === $this->status ? 'no connection' : sprintf('HTTP %d', $this->status);

        return '' !== $this->detail ? sprintf('%s: %s', $what, $this->detail) : $what;
    }
}
