<?php

declare(strict_types=1);

namespace App\Service\Fork\Calendar;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Helper\ReadableBody;
use App\Domain\Interface\EventExtractorInterface;
use App\Entity\Ai\AiFeature;
use App\Service\Ai\AiAssistant;
use App\Service\Calendar\Extraction\ExtractedEvent;
use App\Service\Calendar\Extraction\ExtractionContext;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * FORK (FORK.md): travel bookings read by the chat model, for the mail that
 * carries no markup.
 *
 * Booking.com sends its "booking confirmed" with schema.org markup and its
 * "booking information" without; Trip.com, airport parking and security-lane slots, the airlines' check-in
 * and boarding mails and most hotels send none at all. Those are the mails
 * people search for, and StructuredDataEventExtractor cannot see them.
 *
 * This is the "stage after" that ProposalDetectorInterface and EventSource::Llm
 * reserve, with the one rule they insist on: a guess never reaches the calendar
 * by itself. Every claim is EventSource::Llm, which isTrusted() answers false
 * for, and EventReconciler (FORK) turns an untrusted claim into an offer — the
 * invite card on the mail says "add to calendar?" — and never applies one to an
 * existing event unasked. An invented flight time is a missed flight.
 *
 * Not a proposal detector, although that interface was built for a model:
 * EventProposer refuses exactly this mail on purpose (not Primary, list
 * headers, not personal), and a proposal is one date, where a trip is legs,
 * places and a booking reference. An extractor claim carries all of it, and the
 * reconciler already knows how to offer one.
 *
 * WHAT KEEPS A GUESS HONEST
 * ─────────────────────────
 *   Runs last (priority 10) and only on mail no extractor can read: no
 *   calendar part, no JSON-LD. A mail with markup is the sender's own word,
 *   and a second, guessed copy of the same flight beside it is noise.
 *
 *   A word gate before the model is asked, so the model is asked about mail
 *   that mentions travel and nothing else. Promotions and Forums are never
 *   asked: an offer for a flight is not a flight.
 *
 *   Every item must quote the mail: the evidence must be in the text, the
 *   flight number or reference must be in the text, and a time of day must be
 *   in the text as written. A model that cannot point at its source has
 *   invented it.
 *
 *   The answer is cached per message. "Apply the change" on a held card re-runs
 *   extraction (InviteController::apply()), and a second, different answer
 *   from the model would apply something nobody was shown.
 */
final readonly class ForkLlmTravelExtractor implements EventExtractorInterface
{
    public const string NAME = 'forkLlm';

    /** Below the 90 of markup and visibly a guess. */
    private const int CONFIDENCE = 60;

    private const int MAX_TEXT  = 6000;
    private const int MAX_ITEMS = 10;
    private const int MAX_TITLE = 200;

    /** A month: long enough for a held card to be applied, short enough to forget. */
    private const int CACHE_SECONDS = 2_592_000;

    /** Lower is steadier, and nothing about a timetable wants creativity. */
    private const float TEMPERATURE = 0.0;

    /** Words a mail about a booked trip contains and a mail about anything else rarely does. */
    private const string GATE = '/\b(flug|flight|fl[üu]ge|boarding|bordkarte|abflug|departure|pnr|buchungs(nummer|code|best[äa]tigung)|booking|reservierung|reservation|hotel|zugbindung|bahn|train|fahrkarte|ticket|mietwagen|rental car|itinerary|reiseplan|check-?in)\b/iu';

    /** @var array<string, ExtractionKind> */
    private const array KINDS = [
        'flight' => ExtractionKind::Flight,
        'train'  => ExtractionKind::Train,
        'bus'    => ExtractionKind::Ticket,
        'hotel'  => ExtractionKind::Lodging,
        'car'    => ExtractionKind::Rental,
        'event'  => ExtractionKind::Ticket,
    ];

    /** A leg with no stated end still occupies something; see FlightReservationMapper. */
    private const array NOMINAL_MINUTES = [
        'flight' => 120,
        'train'  => 60,
        'bus'    => 60,
        'car'    => 60,
        'event'  => 120,
    ];

    public function __construct(
        private AiAssistant          $ai,
        private UserTimezoneResolver $timezones,
        private CacheInterface       $cache,
        private LoggerInterface      $logger,
    ) {
    }

    public function priority(): int
    {
        return 10;
    }

    public function stopsCascade(): bool
    {
        return false;
    }

    public function supports(ExtractionContext $context): bool
    {
        if ([] !== $context->calendarParts) {
            return false;
        }

        $html = (string) $context->bodyHtml;

        if (false !== stripos($html, 'ld+json')) {
            return false;
        }

        $category = $context->message->category;

        if (MessageCategory::Promotions === $category || MessageCategory::Forums === $category) {
            return false;
        }

        if (1 !== preg_match(self::GATE, (string) $context->message->subject . "\n" . mb_substr($this->textOf($context), 0, 3000))) {
            return false;
        }

        return true === $this->ai->isEnabledFor(AiFeature::Categorise);
    }

    /**
     * @return list<ExtractedEvent>
     */
    public function extract(ExtractionContext $context): array
    {
        $message = $context->message;
        $anchor  = $message->receivedAt ?? $message->sentAt;

        if (null === $anchor) {
            return [];
        }

        $text  = $this->textOf($context);
        $items = $this->items($context, $text, $anchor);

        $zone   = $this->timezones->resolve($context->account->usr);
        $issuer = $this->issuerDomain($context->fromAddress);
        $events = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            $event = true === is_array($item) ? $this->toExtractedEvent($item, $text, $anchor, $zone, $issuer) : null;

            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    // ── The model ─────────────────────────────────────────────────────────────

    /**
     * The model's items for this message, from the cache when it has been asked
     * before. A failed call is not cached: the next run asks again.
     *
     * @return list<mixed>
     */
    private function items(ExtractionContext $context, string $text, DateTimeImmutable $anchor): array
    {
        $message = $context->message;
        $key     = 'fork_llm_travel_' . hash('sha256', (string) ($message->messageId ?? $message->id) . "\0" . (string) $message->id);

        $answer = $this->cache->get($key, function (ItemInterface $item) use ($context, $text, $anchor): ?array {
            $item->expiresAfter(self::CACHE_SECONDS);

            $answer = $this->ask($context, $text, $anchor);

            if (null === $answer) {
                // Not worth remembering: the model was off, or down.
                $item->expiresAfter(1);
            }

            return $answer;
        });

        $items = $answer['items'] ?? [];

        return true === is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ask(ExtractionContext $context, string $text, DateTimeImmutable $anchor): ?array
    {
        $message = $context->message;

        $system = <<<'PROMPT'
            You read travel bookings out of ONE email: flights, trains, buses, hotel stays, rental cars and event tickets that this email books, confirms, changes, cancels or reminds the recipient of. Answer with ONE JSON object and nothing else: {"items": [ ... ]}, and {"items": []} when there is none.

            Each item is an object with these keys:
            - kind: one of flight, train, bus, hotel, car, event
            - title: short, e.g. "XY 1234 FRA → LIS", "ICE 75 Köln → Basel", or the hotel name
            - start: local clock time where it starts, "YYYY-MM-DDTHH:MM"; for a hotel stay without a check-in time the date alone, "YYYY-MM-DD"
            - start_zone: IANA time zone of the place where it starts, e.g. "Europe/Berlin"
            - end: local clock time where it ends, same format, or null
            - end_zone: IANA time zone of the place where it ends, or null
            - location: where it starts (airport, station, hotel address), or null
            - number: the flight or train number exactly as written, or null
            - reference: the booking reference or PNR exactly as written, or null
            - status: confirmed, pending or cancelled
            - evidence: a short quote copied verbatim from the email that contains the start date or time

            Rules:
            - One item per flight leg, train ride or stay. A return trip is two items.
            - A booking that belongs to a flight but is not the flight itself (a security-lane slot, parking, a lounge, a seat or luggage) is kind "event" with its own time, and its number is null.
            - Never invent a date, time, number or reference that is not written in the email. Leave out anything whose date is not written.
            - Not offers, advertisements, price alerts, loyalty programmes, or trips only mentioned in passing.
            - A year that is not written is the next one after the email's date.
            PROMPT;

        $user = sprintf(
            "Email date: %s\nFrom: %s\nSubject: %s\n\n%s",
            $anchor->format('Y-m-d (l)'),
            $context->fromAddress,
            trim((string) $message->subject),
            mb_substr($text, 0, self::MAX_TEXT),
        );

        $raw = $this->ai->chat(AiFeature::Categorise, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], self::TEMPERATURE);

        if (null === $raw) {
            return null;
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if (false === $start || false === $end || $end < $start) {
            // An answer that is not JSON is an answer: nothing found. Cached as
            // such, so the same mail does not cost the model twice.
            return ['items' => []];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return true === is_array($decoded) ? $decoded : ['items' => []];
    }

    // ── One item ──────────────────────────────────────────────────────────────

    /**
     * @param array<mixed> $item
     */
    private function toExtractedEvent(
        array             $item,
        string            $text,
        DateTimeImmutable $anchor,
        DateTimeZone      $fallbackZone,
        string            $issuer,
    ): ?ExtractedEvent {
        $kindName = mb_strtolower($this->string($item['kind'] ?? null) ?? '');
        $kind     = self::KINDS[$kindName] ?? null;

        if (null === $kind) {
            return null;
        }

        $startRaw = $this->string($item['start'] ?? null);

        if (null === $startRaw) {
            return null;
        }

        $number    = $this->string($item['number'] ?? null);
        $reference = $this->string($item['reference'] ?? null);
        $evidence  = $this->string($item['evidence'] ?? null);

        if (false === $this->isGrounded($text, $startRaw, $number, $reference, $evidence)) {
            $this->logger->info('ForkLlmTravelExtractor: ungrounded item dropped', [
                'kind'  => $kindName,
                'start' => $startRaw,
            ]);

            return null;
        }

        $allDay = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw);

        if (true === $allDay && 'hotel' !== $kindName && 'car' !== $kindName && 'event' !== $kindName) {
            // A flight or a train without a time is not something to put in a
            // day as an all-day block.
            return null;
        }

        $startZone = $this->zone($this->string($item['start_zone'] ?? null)) ?? $fallbackZone;
        $startsAt  = $this->moment($startRaw, $startZone, $allDay);

        if (null === $startsAt) {
            return null;
        }

        // Not in the past of the mail (a day of slack for a reminder sent the
        // night before from another zone), and not absurdly far ahead of it.
        if ($startsAt < $anchor->modify('-1 day') || $startsAt > $anchor->modify('+400 days')) {
            return null;
        }

        $endRaw  = $this->string($item['end'] ?? null);
        $endZone = $this->zone($this->string($item['end_zone'] ?? null)) ?? $startZone;
        $endsAt  = null === $endRaw ? null : $this->moment($endRaw, $endZone, $allDay);

        if (true === $allDay) {
            // An exclusive end: the midnight after the last day.
            $endsAt = null !== $endsAt && $endsAt > $startsAt ? $endsAt->modify('+1 day') : $startsAt->modify('+1 day');
        } elseif (null === $endsAt || $endsAt <= $startsAt || $endsAt > $startsAt->modify('+2 days')) {
            $endsAt = $startsAt->modify(sprintf('+%d minutes', self::NOMINAL_MINUTES[$kindName] ?? 60));
        }

        $title    = $this->clamp($this->string($item['title'] ?? null) ?? $number ?? '', self::MAX_TITLE);
        $location = $this->clamp($this->string($item['location'] ?? null), 300);
        $status   = match (mb_strtolower($this->string($item['status'] ?? null) ?? '')) {
            'cancelled', 'canceled' => EventStatus::Cancelled,
            default                 => EventStatus::Tentative,
        };

        $localDate = $startsAt->setTimezone($startZone)->format('Y-m-d');

        // A flight number on a date is one flight whoever sold the ticket, so
        // the airline's check-in mail and the agency's confirmation are one
        // event. Anything else is the issuer's booking: its reference, or
        // without one its start — never the title, which the model words
        // differently in the confirmation and in the cancellation.
        $identity = null !== $number && in_array($kindName, ['flight', 'train', 'bus'], true)
            ? implode("\0", [$kindName, $this->normalised($number), $localDate])
            : implode("\0", [$kindName, $issuer, null !== $reference ? $this->normalised($reference) : $startsAt->format('Y-m-d\TH:i')]);

        $hash = hash('sha256', 'fork-llm' . "\0" . $identity);

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $hash . '@plmail',
            'title'    => $title ?? '',
            'start'    => $startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
            'duration' => sprintf('PT%dM', intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60)),
            'status'   => $status->value,
        ];

        if (true === $allDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        if (null !== $reference) {
            $jscalendar['description'] = '#' . $reference;
        }

        if (null !== $location) {
            $jscalendar['locations'] = ['1' => ['@type' => 'Location', 'name' => $location]];
        }

        return new ExtractedEvent(
            uid:           $hash . '@plmail',
            dedupKey:      'llm:' . $hash,
            jscalendar:    $jscalendar,
            startsAt:      $startsAt,
            endsAt:        $endsAt,
            extractor:     self::NAME,
            source:        EventSource::Llm,
            confidence:    self::CONFIDENCE,
            title:         $title,
            location:      $location,
            timeZone:      null,
            isAllDay:      $allDay,
            status:        $status,
            kind:          $kind,
            sequence:      0,
            part:          null,
            sourcePayload: [
                'item'   => $item,
                'issuer' => $issuer,
            ],
        );
    }

    /**
     * Whether the item points at words that are really in the mail.
     *
     * The evidence quote, whitespace and case aside. A number or reference
     * given must be in the text, spacing aside ("XY1234" for "XY 1234"). A
     * time of day must be in the text as a clock face, 18:10 or 18.10.
     */
    private function isGrounded(string $text, string $start, ?string $number, ?string $reference, ?string $evidence): bool
    {
        // Bidi marks (Trip.com puts them around "|") are invisible and a model drops them.
        $flat    = mb_strtolower((string) preg_replace('/[\s\x{202A}-\x{202E}]+/u', ' ', $text));
        $compact = (string) preg_replace('/\s+/u', '', $flat);

        if (null === $evidence) {
            return false;
        }

        // Models elide a long quote with "..."; each piece must be in the mail.
        foreach (preg_split('/\s*(?:\.\.\.|…)\s*/u', $evidence) ?: [] as $piece) {
            $piece = mb_strtolower(trim((string) preg_replace('/[\s\x{202A}-\x{202E}]+/u', ' ', $piece)));

            if ('' !== $piece && false === str_contains($flat, $piece)) {
                return false;
            }
        }

        foreach ([$number, $reference] as $code) {
            if (null !== $code && false === str_contains($compact, $this->normalisedLower($code))) {
                return false;
            }
        }

        if (1 === preg_match('/T(\d{2}):(\d{2})/', $start, $m)) {
            $hour   = (int) $m[1];
            $minute = $m[2];
            // 18:10, 18.10 Uhr — but not the date 18.10.2026.
            $faces = '(?<!\d)0?' . $hour . '[:.]' . $minute . '(?!\d)(?!\.\d)';

            if (12 < $hour) {
                $faces .= '|(?<!\d)0?' . ($hour - 12) . ':' . $minute . '\s*p\.?\s?m';
            }

            if (1 !== preg_match('/' . $faces . '/i', $text)) {
                return false;
            }
        }

        return true;
    }

    // ── Small things ──────────────────────────────────────────────────────────

    /** The whole body, quoted history included: a forwarded boarding pass IS the history. */
    private function textOf(ExtractionContext $context): string
    {
        return ReadableBody::of($context->message);
    }

    private function moment(string $raw, DateTimeZone $zone, bool $allDay): ?DateTimeImmutable
    {
        $pattern = true === $allDay ? '/^\d{4}-\d{2}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/';

        if (1 !== preg_match($pattern, $raw)) {
            return null;
        }

        try {
            // All-day dates are UTC midnights, as LodgingReservationMapper's are.
            $at = new DateTimeImmutable($raw, true === $allDay ? new DateTimeZone('UTC') : $zone);
        } catch (\Throwable) {
            return null;
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }

    private function zone(?string $name): ?DateTimeZone
    {
        if (null === $name || false === in_array($name, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new DateTimeZone($name);
    }

    private function string(mixed $value): ?string
    {
        if (false === is_string($value) && false === is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value || 'null' === mb_strtolower($value) ? null : $value;
    }

    private function clamp(?string $value, int $limit): ?string
    {
        return null === $value || '' === $value ? null : mb_substr($value, 0, $limit);
    }

    private function normalised(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $value));
    }

    private function normalisedLower(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $value));
    }

    /** As StructuredDataEventExtractor::issuerDomain(), which is private there. */
    private function issuerDomain(string $address): string
    {
        $at   = strrpos($address, '@');
        $host = mb_strtolower(trim(false === $at ? $address : substr($address, $at + 1)));

        if ('' === $host) {
            return '';
        }

        $labels = explode('.', $host);

        if (3 > count($labels)) {
            return $host;
        }

        $secondLevel = $labels[count($labels) - 2] ?? '';
        $depth       = in_array($secondLevel, ['co', 'com', 'org', 'net', 'ac', 'gov', 'edu'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$depth));
    }
}
