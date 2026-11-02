<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Service\Fork\Search\ForkJsonFieldStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (FORK.md): the search answer's prose, read out of the JSON while the
 * model is still writing it.
 */
final class ForkJsonFieldStreamTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function answers(): iterable
    {
        yield 'plain'        => ['Dein Flug ist am 2. Dezember.'];
        yield 'umlauts'      => ['Bestätigt: Zürich → Hamburg, 15:45 Uhr.'];
        yield 'quotes'       => ['Betreff „Ihre Buchung" und "XY 1234" \\ Ende'];
        yield 'newline'      => ["Erste Zeile.\nZweite Zeile."];
        yield 'emoji'        => ['Gute Reise ✈️ 😀'];
    }

    /** Fed one byte at a time, the worst a model can cut it: the same text comes out. */
    #[DataProvider('answers')]
    public function testEveryCutGivesBackTheSameText(string $answer): void
    {
        foreach ([0, \JSON_UNESCAPED_UNICODE] as $flags) {
            $json = '```json' . "\n" . json_encode(['answer' => $answer, 'important' => [3, 4]], $flags) . "\n```";

            foreach ([1, 2, 3, 5, 11] as $size) {
                $stream = new ForkJsonFieldStream('answer');
                $out    = '';

                foreach (str_split($json, $size) as $chunk) {
                    $piece = $stream->feed($chunk);

                    self::assertTrue(mb_check_encoding($piece, 'UTF-8'), 'every piece is whole characters');
                    $out .= $piece;
                }

                self::assertSame($answer, $out, sprintf('flags %d, chunks of %d', $flags, $size));
            }
        }
    }

    public function testNothingBeforeTheKeyAndNothingAfterTheValue(): void
    {
        $stream = new ForkJsonFieldStream('answer');

        self::assertSame('', $stream->feed('{"important": [1], "title": "nein", '));
        self::assertSame('Ja', $stream->feed('"answer": "Ja'));
        self::assertSame('.', $stream->feed('.", "groups": [{"title": "x"}]'));
        self::assertSame('', $stream->feed('}'));
    }
}
