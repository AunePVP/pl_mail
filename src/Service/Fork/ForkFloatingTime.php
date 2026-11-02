<?php

declare(strict_types=1);

namespace App\Service\Fork;

use App\Service\Calendar\Extraction\StructuredData\MappedEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * FORK (FORK.md): schema.org times without an offset are local clock faces.
 *
 * Node::moment() reads "2026-03-12T18:10:00" as UTC so that the same mail
 * extracts identically wherever it runs. But a timestamp with no offset is not
 * a UTC instant: Google's email markup documentation says it is the local time
 * of the place (the departure airport, the hotel), and Booking.com sends every
 * flight that way. Read as UTC, a 18:10 departure from Frankfurt landed on the
 * calendar at 19:10 in winter and 20:10 in summer.
 *
 * The zone of the place is not in the markup and would need an airport table.
 * The reader's own zone is the next best thing and is right for every trip
 * that starts in it — which is most of them, and all of the domestic and
 * European ones. A long-haul return leg is still an hour or more out, exactly
 * as it was before, so this is never worse than reading UTC.
 *
 * Only when EVERY time in the node is offset-less. A node that states one
 * offset anywhere is a sender who knows how to write them, and its bare times
 * are left as the original reads them.
 */
final class ForkFloatingTime
{
    /** A date, a time of day, and nothing after it: no Z, no ±hh:mm. */
    private const string FLOATING = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/';

    /** A date and a time of day followed by Z or an offset. */
    private const string ANCHORED = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}.*(Z|[+-]\d{2}:?\d{2})$/i';

    /**
     * @param array<mixed> $data the node's JSON-LD, verbatim
     */
    public static function isFloating(array $data): bool
    {
        $floating = false;
        $anchored = false;

        array_walk_recursive($data, static function (mixed $value) use (&$floating, &$anchored): void {
            if (false === is_string($value)) {
                return;
            }

            $value = trim($value);

            if (1 === preg_match(self::FLOATING, $value)) {
                $floating = true;
            } elseif (1 === preg_match(self::ANCHORED, $value)) {
                $anchored = true;
            }
        });

        return $floating && false === $anchored;
    }

    /**
     * The same event with its clock faces read in $zone rather than UTC.
     *
     * The identity is left alone on purpose: it was built from the clock-face
     * date, which this does not change, so events already on a calendar keep
     * their dedup key and a re-extraction moves them instead of duplicating them.
     */
    public static function localised(MappedEvent $mapped, DateTimeZone $zone): MappedEvent
    {
        if (true === $mapped->isAllDay) {
            return $mapped;
        }

        return new MappedEvent(
            type:        $mapped->type,
            identity:    $mapped->identity,
            startsAt:    self::reread($mapped->startsAt, $zone),
            endsAt:      self::reread($mapped->endsAt, $zone),
            kind:        $mapped->kind,
            title:       $mapped->title,
            location:    $mapped->location,
            isAllDay:    $mapped->isAllDay,
            description: $mapped->description,
        );
    }

    /** The UTC clock face of $at, as the same clock face in $zone, back in UTC. */
    private static function reread(DateTimeImmutable $at, DateTimeZone $zone): DateTimeImmutable
    {
        $utc   = new DateTimeZone('UTC');
        $local = new DateTimeImmutable($at->setTimezone($utc)->format('Y-m-d H:i:s'), $zone);

        return $local->setTimezone($utc);
    }
}
