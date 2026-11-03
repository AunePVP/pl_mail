<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FORK (FORK.md): the coming events a search is probably about, each with the
 * mail that belongs to it.
 *
 * Somebody who types "Flug" wants their next flight, not 284 messages about
 * flights. The calendar already knows the next flight — extraction put it
 * there, from those very messages — so the answer is a query, not a model:
 * instant, and never wrong about a time.
 *
 * An event matches when the query names its kind ("Flug" → a flight) or one
 * of the query's words is in its title or place. Its mail is every message an
 * extractor read it from (EventSourceLink), plus the mail whose subject names
 * the same flight or train number — the parking booking and the gate change
 * that no extractor claimed are still about XY 1234. That is the grouping:
 * eight messages about one trip become one line with eight links. Only the
 * mail around this trip, though: the same number flies every week (mailFor()).
 *
 * Offers (an event extraction only offered, see EventReconciler) are included
 * and marked, because "is my flight on the calendar?" is half of what the
 * search is asking, and an offer is the place to say yes.
 */
final readonly class ForkTripFinder
{
    /** Yesterday's flight is still worth showing; it may be the one somebody is on. */
    private const string LOOKBACK = '-1 day';

    private const string HORIZON = '+400 days';

    private const int MAX_EVENTS = 5;

    /** What between() lists at most: a busy fortnight, not a year. */
    private const int MAX_PERIOD_EVENTS = 25;

    private const int MAX_MAIL = 8;

    /** A parking or seat booking may be made a little before the flight is on the calendar's books. */
    private const string BEFORE_BOOKING = '-7 days';

    /** For an event no mail was read from: how far back its number still means it. */
    private const string UNBOOKED_LOOKBACK = '-90 days';

    /** A receipt or a delay notice a day or two after still belongs; later mail is about a later flight. */
    private const string AFTERMATH = '+2 days';

    /**
     * Query words that name a kind of event rather than something in its title.
     * Matched as prefixes, so "Flüge", "Flugbuchung" and "flights" all count.
     *
     * @var array<string, ExtractionKind>
     */
    private const array KIND_WORDS = [
        'flug'       => ExtractionKind::Flight,
        'flüge'      => ExtractionKind::Flight,
        'flight'     => ExtractionKind::Flight,
        'flieg'      => ExtractionKind::Flight,
        'boarding'   => ExtractionKind::Flight,
        'zug'        => ExtractionKind::Train,
        'bahn'       => ExtractionKind::Train,
        'train'      => ExtractionKind::Train,
        'hotel'      => ExtractionKind::Lodging,
        'übernacht'  => ExtractionKind::Lodging,
        'unterkunft' => ExtractionKind::Lodging,
        'mietwagen'  => ExtractionKind::Rental,
        'paket'      => ExtractionKind::Delivery,
        'bestell'    => ExtractionKind::Delivery,
        'order'      => ExtractionKind::Delivery,
        'lieferung'  => ExtractionKind::Delivery,
        'sendung'    => ExtractionKind::Delivery,
        'ticket'     => ExtractionKind::Ticket,
        // A coach booking is read as a ticket (BusReservation in the markup).
        'bus'        => ExtractionKind::Ticket,
        'fernbus'    => ExtractionKind::Ticket,
        'konzert'    => ExtractionKind::Ticket,
        'restaurant' => ExtractionKind::Dining,
        'tisch'      => ExtractionKind::Dining,
    ];

    /**
     * Words of a question rather than of a title. "was habe ich als letztes
     * bestellt" found every flight from Zürich: "ich" is in "Zurich Airport".
     */
    public const array STOP_WORDS = [
        'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'mein', 'meine', 'meinen', 'meiner', 'mir', 'mich',
        'was', 'wer', 'wo', 'wann', 'wie', 'welche', 'welcher', 'welches', 'habe', 'hab', 'hat', 'hatte',
        'ist', 'sind', 'war', 'bin', 'wird', 'als', 'am', 'an', 'auf', 'aus', 'bei', 'bis', 'das', 'dem',
        'den', 'der', 'die', 'ein', 'eine', 'einen', 'für', 'im', 'in', 'mit', 'nach', 'noch', 'nicht',
        'oder', 'und', 'von', 'vom', 'vor', 'zu', 'zum', 'zur', 'letzte', 'letzten', 'letztes', 'nächste',
        'nächsten', 'nächstes', 'gibt', 'the', 'and', 'for', 'what', 'when', 'where', 'did', 'have', 'last',
        'next', 'were', 'my', 'from', 'with',
    ];

    /** "XY 1234", "AB987", "ICE 75": two letters or a letter and a digit, then digits. */
    private const string SERVICE_NUMBER = '/\b((?:[A-Z]{2,3}|[A-Z]\d|\d[A-Z]))\s?(\d{1,4})\b/u';

    public function __construct(
        private EntityManagerInterface $em,
        private Connection             $connection,
    ) {
    }

    /**
     * @return list<ForkTrip>
     */
    public function find(User $user, string $freeText, ?DateTimeImmutable $now = null): array
    {
        $words = $this->words($freeText);

        if ([] === $words) {
            return [];
        }

        $now   = $now ?? new DateTimeImmutable();
        $kinds = $this->kindsNamedBy($words);

        /** @var list<CalendarEvent> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CalendarEvent::class, 'e')
            ->where('e.usr = :user')
            ->andWhere('e.startsAt >= :from AND e.startsAt < :to')
            ->andWhere('e.status != :cancelled')
            ->setParameter('user', $user)
            ->setParameter('from', $now->modify(self::LOOKBACK))
            ->setParameter('to', $now->modify(self::HORIZON))
            ->setParameter('cancelled', EventStatus::Cancelled)
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $this->trips(
            $user,
            array_values(array_filter($candidates, fn (CalendarEvent $event): bool => $this->matches($event, $words, $kinds))),
            self::MAX_EVENTS,
        );
    }

    /**
     * Everything on the calendar between two days, both included — for the
     * search chat, when a question names a time rather than a thing: "was
     * steht die Woche an" has no word any title contains.
     *
     * @return list<ForkTrip> by start
     */
    public function between(User $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<CalendarEvent> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CalendarEvent::class, 'e')
            ->where('e.usr = :user')
            // Overlapping the days, so a trip that began yesterday is this week's too.
            ->andWhere('e.startsAt < :to AND (e.endsAt >= :from OR (e.endsAt IS NULL AND e.startsAt >= :from))')
            ->andWhere('e.status != :cancelled')
            ->setParameter('user', $user)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(0, 0)->modify('+1 day'))
            ->setParameter('cancelled', EventStatus::Cancelled)
            ->orderBy('e.startsAt', 'ASC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $this->trips($user, $candidates, self::MAX_PERIOD_EVENTS);
    }

    /**
     * @param list<CalendarEvent> $candidates by start
     *
     * @return list<ForkTrip>
     */
    private function trips(User $user, array $candidates, int $max): array
    {
        $events = [];
        $seen   = [];

        foreach ($candidates as $event) {
            if (ParticipationStatus::Declined === $event->myParticipation) {
                continue;
            }

            // A meeting on two calendars is two rows and one thing.
            $twin = mb_strtolower(trim((string) $event->title)) . '|' . $event->startsAt?->format('c');

            if (true === isset($seen[$twin])) {
                continue;
            }

            $seen[$twin] = true;
            $events[]    = $event;

            if ($max <= count($events)) {
                break;
            }
        }

        return array_map(fn (CalendarEvent $event): ForkTrip => new ForkTrip(
            event:   $event,
            isOffer: ParticipationStatus::NeedsAction === $event->myParticipation,
            mail:    $this->mailFor($user, $event),
        ), $events);
    }

    /**
     * @param list<string>         $words
     * @param list<ExtractionKind> $kinds
     */
    private function matches(CalendarEvent $event, array $words, array $kinds): bool
    {
        if (null !== $event->kind && true === in_array($event->kind, $kinds, true)) {
            return true;
        }

        $haystack = mb_strtolower((string) $event->title . ' ' . (string) $event->location);

        foreach ($words as $word) {
            if (3 > mb_strlen($word) || true === in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            // At the start of a word: "lis" is Lisbon, and not "Kalisz".
            if (1 === preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/u', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mail an extractor read this event from, then the mail whose subject
     * names its flight or train number. Newest first, the user's own only.
     *
     * @return list<array{id: int, thread: ?int, subject: string, from: string, receivedAt: ?DateTimeImmutable}>
     */
    private function mailFor(User $user, CalendarEvent $event): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.thread_id, m.subject, coalesce(nullif(m.from_name, \'\'), m.from_address) AS sender, m.received_at
               FROM event_source_link l
               JOIN message m ON m.id = l.message_id
               JOIN account a ON a.id = m.account_id
              WHERE l.event_id = :event AND a.usr_id = :user
              ORDER BY m.received_at DESC
              LIMIT 50',
            ['event' => $event->id, 'user' => $user->id],
        );

        $numbers = $this->serviceNumbers((string) $event->title);

        if ([] !== $numbers && null !== $event->startsAt) {
            $patterns = [];

            foreach ($numbers as [$carrier, $digits]) {
                // "XY 1234" and "XY1234", but not "XY 12345".
                $patterns[] = sprintf('\m%s\s?%s\M', preg_quote($carrier, '/'), $digits);
            }

            // A flight number comes back every week. The parking booking for
            // April's XY 1234 names the same number as December's, so a mail is
            // only this trip's when it is not already another event's, came no
            // earlier than this trip's own booking, and no other XY 1234 on the
            // calendar lies between it and this one.
            $rows = array_merge($rows, $this->connection->fetchAllAssociative(
                'SELECT m.id, m.thread_id, m.subject, coalesce(nullif(m.from_name, \'\'), m.from_address) AS sender, m.received_at
                   FROM message m
                   JOIN account a ON a.id = m.account_id
                  WHERE a.usr_id = :user
                    AND m.received_at >= :since
                    AND m.received_at < :until
                    AND m.subject ~* :pattern
                    AND NOT EXISTS (
                        SELECT 1 FROM event_source_link o WHERE o.message_id = m.id AND o.event_id <> :event
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM calendar_event e
                         WHERE e.usr_id = :user AND e.id <> :event AND e.status <> :cancelled
                           AND e.starts_at > m.received_at AND e.starts_at < :start
                           AND e.title ~* :pattern
                    )
                  ORDER BY m.received_at DESC
                  LIMIT 50',
                [
                    'user'      => $user->id,
                    'event'     => $event->id,
                    'cancelled' => EventStatus::Cancelled->value,
                    'since'     => $this->numberedMailSince($event, $rows)->format('Y-m-d H:i:s'),
                    'until'     => ($event->endsAt ?? $event->startsAt)->modify(self::AFTERMATH)->format('Y-m-d H:i:s'),
                    'start'     => $event->startsAt->format('Y-m-d H:i:s'),
                    'pattern'   => implode('|', $patterns),
                ],
            ));
        }

        $mail    = [];
        $threads = [];

        foreach ($rows as $row) {
            // One line per conversation: a confirmation and its resend are one.
            $thread = null === $row['thread_id'] ? null : (int) $row['thread_id'];
            $key    = $thread ?? -(int) $row['id'];

            if (true === isset($threads[$key])) {
                continue;
            }

            $threads[$key] = true;
            $mail[]        = [
                'id'         => (int) $row['id'],
                'thread'     => $thread,
                'subject'    => trim((string) $row['subject']),
                'from'       => trim((string) $row['sender']),
                'receivedAt' => null === $row['received_at'] ? null : new DateTimeImmutable((string) $row['received_at']),
            ];
        }

        usort($mail, static fn (array $a, array $b): int => $b['receivedAt'] <=> $a['receivedAt']);

        return array_slice($mail, 0, self::MAX_MAIL);
    }

    /**
     * The earliest a mail naming the trip's number can be about it: a few days
     * before the first mail the event was read from (its booking), or, for an
     * event no mail made, a season before it starts.
     *
     * @param list<array<string, mixed>> $linked
     */
    private function numberedMailSince(CalendarEvent $event, array $linked): DateTimeImmutable
    {
        $dates = array_filter(array_map(
            static fn (array $row): ?string => null === $row['received_at'] ? null : (string) $row['received_at'],
            $linked,
        ));

        if ([] === $dates) {
            return ($event->startsAt ?? new DateTimeImmutable())->modify(self::UNBOOKED_LOOKBACK);
        }

        return new DateTimeImmutable(min($dates))->modify(self::BEFORE_BOOKING);
    }

    /**
     * @return list<array{0: string, 1: string}> carrier and digits
     */
    private function serviceNumbers(string $title): array
    {
        if (0 === preg_match_all(self::SERVICE_NUMBER, $title, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        return array_map(static fn (array $m): array => [$m[1], $m[2]], $matches);
    }

    /**
     * @return list<string>
     */
    private function words(string $freeText): array
    {
        $words = preg_split('/[\s,;:"()]+/u', mb_strtolower(trim($freeText))) ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => '' !== $w));
    }

    /**
     * @param list<string> $words
     *
     * @return list<ExtractionKind>
     */
    private function kindsNamedBy(array $words): array
    {
        $kinds = [];

        foreach ($words as $word) {
            foreach (self::KIND_WORDS as $prefix => $kind) {
                if (true === str_starts_with($word, $prefix)) {
                    $kinds[] = $kind;
                }
            }
        }

        return array_values(array_unique($kinds, \SORT_REGULAR));
    }
}
