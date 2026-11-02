<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Service\Calendar\Extraction\ExtractedEvent;
use App\Service\Fork\ForkFloatingTime;
use App\Service\Insight\Extractor\FlightExtractor;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FORK (FORK.md): offset-less schema.org times are the reader's clock faces.
 *
 * The flight fixture is Booking.com's shape as it arrives: no reservation
 * number, the airline code apart from the digits, and times with no offset.
 */
final class ForkFloatingTimeTest extends KernelTestCase
{
    public function testAnOffsetLessFlightIsReadInTheReadersZone(): void
    {
        $events = $this->extract('2026-12-10T07:35:00', '2026-12-10T08:30:00', 'Europe/Berlin');

        self::assertCount(1, $events);
        // 07:35 in Frankfurt in December is 06:35Z, not 07:35Z.
        self::assertSame('2026-12-10 06:35', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-12-10 07:30', $events[0]->endsAt->format('Y-m-d H:i'));
        // The key is built from the clock-face date and must not move, or every
        // event already on a calendar would be duplicated by the next extraction.
        self::assertSame('XY1234/2026-12-10', $events[0]->sourcePayload['identity']);
    }

    public function testSummerTimeIsTwoHours(): void
    {
        $events = $this->extract('2026-07-10T07:35:00', null, 'Europe/Berlin');

        self::assertSame('2026-07-10 05:35', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testAnOffsetIsStillTheSendersWord(): void
    {
        $events = $this->extract('2026-12-10T07:35:00+01:00', '2026-12-10T08:30:00+01:00', 'America/New_York');

        self::assertSame('2026-12-10 06:35', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testOneOffsetAnywhereLeavesTheNodeAlone(): void
    {
        self::assertFalse(ForkFloatingTime::isFloating([
            'departureTime' => '2026-12-10T07:35:00',
            'arrivalTime'   => '2026-12-10T08:30:00Z',
        ]));
        self::assertTrue(ForkFloatingTime::isFloating(['a' => ['b' => '2026-12-10 07:35']]));
        self::assertFalse(ForkFloatingTime::isFloating(['checkinTime' => '2026-12-07']));
    }

    /**
     * FORK (FORK.md): the flight card the insights read out of the text has
     * the same problem — "15:45" in a mail is the reader's clock, not UTC.
     */
    public function testTheFlightInsightReadsTheTimeInTheReadersZone(): void
    {
        self::bootKernel();

        $user           = new User();
        $user->timezone = 'Europe/Berlin';

        $account      = new Account();
        $account->usr = $user;

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = 'noreply@lufthansa.com';
        $message->subject     = 'Ihre Buchungsbestätigung';
        $message->bodyText    = "Ihr Flug LH 1234 am 24.12.2026 um 09:15 Uhr\nStrecke: FRA–JFK";
        $message->receivedAt  = new DateTimeImmutable('2026-11-10 08:00:00');

        $drafts = self::getContainer()->get(FlightExtractor::class)->extract($message);

        self::assertCount(1, $drafts);
        // 09:15 in Frankfurt in December is 08:15Z.
        self::assertSame('2026-12-24 08:15', $drafts[0]->happensAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        // The card is keyed on the day the mail states, as it was before.
        self::assertSame('LH1234@2026-12-24', $drafts[0]->dedupeKey);
    }

    /** @return list<ExtractedEvent> */
    private function extract(string $departs, ?string $arrives, string $zone): array
    {
        self::bootKernel();

        $user           = new User();
        $user->email    = 'floating-' . uniqid('', true) . '@example.test';
        $user->timezone = $zone;

        $account           = new Account();
        $account->usr      = $user;
        $account->username = 'floating@example.test';

        $json = sprintf(
            '[{"@context":"http://schema.org","@type":"FlightReservation","reservationNumber":"",'
            . '"reservationStatus":"http://schema.org/Confirmed",'
            . '"reservationFor":{"@type":"Flight","flightNumber":"1234",'
            . '"airline":{"@type":"Airline","name":"Example Air","iataCode":"XY"},'
            . '"departureAirport":{"@type":"Airport","name":"Frankfurt Airport","iataCode":"FRA"},'
            . '"departureTime":"%s",'
            . '"arrivalAirport":{"@type":"Airport","name":"Munich Airport","iataCode":"MUC"}%s}}]',
            $departs,
            null === $arrives ? '' : sprintf(',"arrivalTime":"%s"', $arrives),
        );

        $message                 = new Message();
        $message->account        = $account;
        $message->fromAddress    = 'noreply@booking.com';
        $message->receivedAt     = new DateTimeImmutable('2026-10-01 12:00:00');
        $message->hasAttachments = false;
        $message->bodyHtml       = '<html><body><script type="application/ld+json">' . $json . '</script></body></html>';

        return self::getContainer()->get(EventExtractionRunner::class)->run($message);
    }
}
