<?php

declare(strict_types=1);

namespace App\Tests\Twig\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Twig\Fork\ForkUnsubscribeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * FORK (FORK.md): the Unsubscribe button and the List-Unsubscribe reading
 * behind it. The header shapes in the provider are the ones found in the
 * production mailboxes, with the tokens replaced.
 */
final class ForkUnsubscribeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{?string, array{url: string}|array{to: string}|null}>
     */
    public static function headers(): iterable
    {
        yield 'no header' => [null, null];
        yield 'link only' => ['<https://api.example.com/unsubscribe?u=abc>', ['url' => 'https://api.example.com/unsubscribe?u=abc']];
        yield 'link preferred though listed second' => [
            '<mailto:unsubscribe@news.example.com?subject=unsub>, <https://data.news.example.com/u/v1?p=1&q=2>',
            ['url' => 'https://data.news.example.com/u/v1?p=1&q=2'],
        ];
        yield 'no space between targets' => [
            '<https://u.example.eu/x?1=1>,<mailto:list@example.eu?subject=x>',
            ['url' => 'https://u.example.eu/x?1=1'],
        ];
        yield 'mailto only' => ['<mailto:abc+def@unsubscribe.example.com?subject=stop>', ['to' => 'abc+def@unsubscribe.example.com']];
        yield 'bare link without brackets' => ['https://account.example.com/profile/x?CTID=0', ['url' => 'https://account.example.com/profile/x?CTID=0']];
        yield 'encoded words' => [
            '=?us-ascii?Q?<https://u.example.com/x=3Fa=3D1&?= =?us-ascii?Q?b=3D2>?=',
            ['url' => 'https://u.example.com/x?a=1&b=2'],
        ];
        yield 'marketo link is not an encoded word' => [
            '<https://email.example.com/u/NjA=?e=x>, <mailto:unsub@mktomail.example.com>',
            ['url' => 'https://email.example.com/u/NjA=?e=x'],
        ];
        yield 'encoded word after an empty mailto' => [
            '<mailto:>, =?utf-8?Q?<https=3A=2F=2Fwww.example.de=2Foc>?=',
            ['url' => 'https://www.example.de/oc'],
        ];
        yield 'bare link folded' => ['https://shop.example.de/cancel?t=ab cd&s=1', ['url' => 'https://shop.example.de/cancel?t=abcd&s=1']];
        yield 'folded inside the brackets' => ["<https://u.example.com/a\r\n b>", ['url' => 'https://u.example.com/ab']];
        yield 'javascript is not a link' => ['<javascript:alert(1)>', null];
        yield 'a relative path is not a link' => ['</unsubscribe>', null];
        yield 'not an address' => ['<mailto:nobody>', null];
        yield 'free text' => ['Reply with STOP', null];
    }

    /**
     * @param array{url: string}|array{to: string}|null $expected
     */
    #[DataProvider('headers')]
    public function testReadsTheTargetFromTheHeader(?string $header, ?array $expected): void
    {
        self::assertSame($expected, ForkUnsubscribeExtension::parse($header));
    }

    public function testTheHeaderAndMenuGetALinkThatOpensInANewTab(): void
    {
        $html = $this->render($this->message('<https://u.example.com/x?a=1&b=2>'), 'chip')
            . $this->render($this->message('<https://u.example.com/x?a=1&b=2>'), 'menu');

        self::assertSame(2, substr_count($html, 'href="https://u.example.com/x?a=1&amp;b=2"'));
        self::assertSame(2, substr_count($html, 'target="_blank"'));
        self::assertSame(2, substr_count($html, 'rel="noopener noreferrer nofollow"'));
    }

    public function testAMailtoOpensADraftToThatAddress(): void
    {
        $html = $this->render($this->message('<mailto:stop@example.com>'), 'chip');

        self::assertStringContainsString('to=stop@example.com', $html);
        self::assertStringContainsString('data-turbo-frame="compose_dock"', $html);
    }

    public function testNothingWithoutTheHeader(): void
    {
        self::assertStringNotContainsString('data-fork-unsubscribe', $this->render($this->message(null), 'chip'));
    }

    public function testNothingInSpam(): void
    {
        $spam       = new Label();
        $spam->role = LabelRole::Spam;

        $message = $this->message('<https://u.example.com/x>');
        $message->addLabel($spam);

        self::assertStringNotContainsString('data-fork-unsubscribe', $this->render($message, 'chip'));
    }

    private function message(?string $header): Message
    {
        $message          = new Message();
        $message->headers = null === $header ? [] : ['list-unsubscribe' => $header];

        return $message;
    }

    private function render(Message $message, string $variant): string
    {
        self::bootKernel();

        return self::getContainer()->get(Environment::class)->render('fork/_unsubscribe.html.twig', [
            'message' => $message,
            'variant' => $variant,
        ]);
    }
}
