<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Calendar\CalendarEvent;
use DateTimeImmutable;

/** FORK (FORK.md): one coming event a search is about, and its mail. See ForkTripFinder. */
final readonly class ForkTrip
{
    /**
     * @param list<array{id: int, thread: ?int, subject: string, from: string, receivedAt: ?DateTimeImmutable}> $mail
     */
    public function __construct(
        public CalendarEvent $event,
        /** Only offered by extraction, not yet on the calendar. */
        public bool          $isOffer,
        public array         $mail,
    ) {
    }
}
