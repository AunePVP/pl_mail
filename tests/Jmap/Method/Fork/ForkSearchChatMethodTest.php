<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Fork;

use App\Jmap\Method\Fork\ForkSearchChatMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;

/**
 * FORK (FORK.md 22): one turn of the AI search chat over JMAP. The turn itself
 * is tested in ForkSearchChatTest; this checks the envelope and that a chat
 * with no model answers with error rather than failing the request.
 */
final class ForkSearchChatMethodTest extends JmapTestCase
{
    private ForkSearchChatMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(ForkSearchChatMethod::class);
    }

    public function testWithoutAModelTheTurnSaysSo(): void
    {
        $result = $this->method->handle(['message' => 'Wann fliege ich?'], $this->context());

        self::assertTrue($result['error']);
        self::assertNull($result['answer']);
        self::assertSame([], $result['important']);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->method->handle(['message' => '  '], $this->context());
    }
}
