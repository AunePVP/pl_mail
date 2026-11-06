<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Jmap\Method\Fork\ForkUnsubscribeGetMethod;
use App\Tests\Jmap\JmapTestCase;

/**
 * FORK (FORK.md 22): the web reader's Unsubscribe target over JMAP. The
 * finding rules themselves are tested in ForkUnsubscribeTest and
 * ForkUnsubscribeFinderTest; this checks the shape the app reads and that the
 * same exclusions apply.
 */
final class ForkUnsubscribeGetMethodTest extends JmapTestCase
{
    private ForkUnsubscribeGetMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(ForkUnsubscribeGetMethod::class);
    }

    public function testHeaderLinkMailtoBodyLinkAndNothing(): void
    {
        $link = $this->receivedMessage();
        $link->headers = ['list-unsubscribe' => '<mailto:off@example.test>, <https://example.test/off>'];

        $mailto = $this->receivedMessage();
        $mailto->headers = ['list-unsubscribe' => '<mailto:off@example.test?subject=stop>'];

        $body = $this->receivedMessage();
        $body->bodyHtml = '<p>Newsletter</p><p><a href="https://example.test/bye">Abmelden</a></p>';

        $plain = $this->receivedMessage();
        $plain->bodyText = 'Hi, see you tomorrow.';
        $this->em->flush();

        $result = $this->method->handle([
            'accountId' => $this->accountId(),
            'ids' => [(string) $link->id, (string) $mailto->id, (string) $body->id, (string) $plain->id, '999999999'],
        ], $this->context());

        $byId = array_column($result['list'], null, 'id');

        self::assertSame(['id' => (string) $link->id, 'url' => 'https://example.test/off', 'fromBody' => false], $byId[(string) $link->id]);
        self::assertSame(['id' => (string) $mailto->id, 'mailto' => 'off@example.test'], $byId[(string) $mailto->id]);
        self::assertSame(['id' => (string) $body->id, 'url' => 'https://example.test/bye', 'fromBody' => true], $byId[(string) $body->id]);
        self::assertArrayNotHasKey((string) $plain->id, $byId);
        self::assertSame(['999999999'], $result['notFound']);
    }

    public function testNothingForSpam(): void
    {
        $spam = $this->receivedMessage([LabelRole::Spam]);
        $spam->headers = ['list-unsubscribe' => '<https://example.test/off>'];
        $this->em->flush();

        $result = $this->method->handle(['accountId' => $this->accountId(), 'ids' => [(string) $spam->id]], $this->context());

        self::assertSame([], $result['list']);
    }
}
