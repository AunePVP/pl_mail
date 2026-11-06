<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Fork;

use App\Jmap\Method\Fork\ForkSearchInsightMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;

/**
 * FORK (FORK.md 22): the web search's insight panels over JMAP. What each
 * panel finds is tested with the services (ForkSearchInsightTest,
 * ForkSearchRerankTest); this checks the envelope a client reads, and that a
 * part whose service is off is empty rather than an error.
 */
final class ForkSearchInsightMethodTest extends JmapTestCase
{
    private ForkSearchInsightMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(ForkSearchInsightMethod::class);
    }

    public function testOperatorsOnlyAskNothing(): void
    {
        $result = $this->method->handle(['query' => 'from:someone@example.test'], $this->context());

        self::assertSame(['best' => null, 'trips' => null, 'answer' => null], $result);
    }

    public function testOnlyTheAskedPartsAreAnswered(): void
    {
        $result = $this->method->handle(['query' => 'Flug', 'parts' => ['trips']], $this->context());

        self::assertSame([], $result['trips']);
        self::assertNull($result['best']);
        self::assertNull($result['answer']);
    }

    public function testBestWithoutRerankerIsEmpty(): void
    {
        $result = $this->method->handle(['query' => 'Rechnung', 'parts' => ['best']], $this->context());

        self::assertSame([], $result['best']);
    }

    public function testUnknownPartIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->method->handle(['query' => 'Flug', 'parts' => ['everything']], $this->context());
    }
}
