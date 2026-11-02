<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\MimeHeaderHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORK (FORK.md): a character split across two encoded words.
 */
final class ForkMimeSplitCharacterTest extends TestCase
{
    #[DataProvider('headers')]
    public function testDecode(string $raw, string $expected): void
    {
        self::assertSame($expected, MimeHeaderHelper::decode($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headers(): iterable
    {
        // The shape Booking.com sends: "ä" is C3 A4, and the first word ends
        // after C3.
        yield 'utf-8 split between base64 words' => [
            "=?UTF-8?B?SWhyZSBCdWNodW5nIG11c3Mgbm9jaCBiZXN0ww==?=\r\n  =?UTF-8?B?pHRpZ3Qgd2VyZGVu?=",
            'Ihre Buchung muss noch bestätigt werden',
        ];

        yield 'utf-8 split between q words' => [
            '=?utf-8?Q?best=C3?= =?UTF-8?Q?=A4tigt?=',
            'bestätigt',
        ];

        yield 'two charsets side by side stay apart' => [
            '=?ISO-8859-1?Q?J=F6rg?= =?UTF-8?Q?_gr=C3=BC=C3=9Ft?=',
            'Jörg grüßt',
        ];

        yield 'a broken word between two good ones' => [
            '=?UTF-8?B?R3LDvA==?= =?UTF-8?B?!!!?= =?UTF-8?B?w59l?=',
            'Grü=?UTF-8?B?!!!?=ße',
        ];
    }
}
