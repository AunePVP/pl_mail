<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

/**
 * FORK (FORK.md 27): why a message could not be filed under a project. The
 * reason is a translation key's last part (message.fork_project.<reason>).
 */
final class ForkProjectRefused extends \RuntimeException
{
    /** Projektakte did not answer, so the number cannot be checked. */
    public const string UNAVAILABLE = 'unavailable';

    /** Projektakte answered and knows no such project. */
    public const string UNKNOWN = 'unknown';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }
}
