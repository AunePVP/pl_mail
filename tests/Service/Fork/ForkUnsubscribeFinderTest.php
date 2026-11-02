<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Service\Fork\ForkUnsubscribeFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (FORK.md): the unsubscribe link in a mail's own text. The cases are
 * shaped after mail in the production mailboxes, the misses among them: each
 * "not …" case is a link the first version of this took and should not have.
 */
final class ForkUnsubscribeFinderTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function bodies(): iterable
    {
        yield 'nothing' => [null, null, null];

        yield 'a link that says it' => [
            '<p>News</p><a href="https://example.com/news">Read</a><p><a href="https://example.com/u?id=1">Newsletter abbestellen</a></p>',
            null,
            'https://example.com/u?id=1',
        ];
        yield 'the one link of a sentence that says it' => [
            '<p>Wenn du keine weiteren Nachrichten zu unseren Promotions erhalten möchtest, kannst du dich '
                . '<a href="https://clicks.example.com/f/a/2">hier</a> abmelden.</p>',
            null,
            'https://clicks.example.com/f/a/2',
        ];
        yield 'a link that says it beats a sentence' => [
            '<p>To stop these, click <a href="https://example.com/a">here</a> to unsubscribe.</p>'
                . '<p><a href="https://example.com/b">Unsubscribe</a></p>',
            null,
            'https://example.com/b',
        ];
        yield 'the last of several, the footer' => [
            '<a href="https://example.com/top">unsubscribe</a><div>…</div><a href="https://example.com/footer">Unsubscribe</a>',
            null,
            'https://example.com/footer',
        ];
        yield 'an image button with alt text' => [
            '<a href="https://example.com/u"><img src="x.png" alt="Abmelden"></a>',
            null,
            'https://example.com/u',
        ];
        yield 'a preferences link in the sentence' => [
            '<p>To unsubscribe, <a href="https://example.com/x">manage preferences</a>.</p>',
            null,
            'https://example.com/x',
        ];
        yield 'not a footer row of several links' => [
            '<p>Abmelden: <a href="https://example.com/a">hier</a> | <a href="https://example.com/b">dort</a></p>',
            null,
            null,
        ];
        yield 'not the help centre a sentence names' => [
            '<p>To unsubscribe, see our <a href="https://example.com/help">Help Centre</a>.</p>',
            null,
            null,
        ];
        yield 'not a privacy policy' => [
            '<p>Sie können sich jederzeit abmelden. Mehr in der <a href="https://example.com/p">Datenschutzerklärung</a>.</p>',
            null,
            null,
        ];
        yield 'not Google Analytics opt-out' => [
            '<p><a href="https://tools.google.com/dlpage/gaoptout">https://tools.google.com/dlpage/gaoptout</a></p>',
            null,
            null,
        ];
        yield 'not a quoted newsletter' => [
            '<p>Siehe unten.</p><blockquote><a href="https://example.com/u">Abmelden</a></blockquote>',
            null,
            null,
        ];
        yield 'not a mailto or a relative link' => [
            '<a href="mailto:x@example.com">Unsubscribe</a><a href="/u">Unsubscribe</a>',
            null,
            null,
        ];
        yield 'not a long section that mentions it' => [
            '<div>' . str_repeat('Lorem ipsum dolor sit amet. ', 20) . 'abmelden <a href="https://example.com/x">hier</a></div>',
            null,
            null,
        ];

        yield 'plain text, URL on the line' => [null, "Hallo\n\nAbmelden: https://example.com/u?a=1\n", 'https://example.com/u?a=1'];
        yield 'plain text, URL on the next line' => [null, "To unsubscribe, visit\nhttps://example.com/u\n", 'https://example.com/u'];
        yield 'plain text, quoted' => [null, "> Unsubscribe: https://example.com/u\n", null];
    }

    #[DataProvider('bodies')]
    public function testFindsTheUnsubscribeLink(?string $html, ?string $text, ?string $expected): void
    {
        self::assertSame($expected, ForkUnsubscribeFinder::find($html, $text));
    }
}
