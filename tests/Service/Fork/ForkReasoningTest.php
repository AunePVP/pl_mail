<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Domain\DTO\Ai\AiConnection;
use App\Domain\Enum\Ai\AiProvider;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use App\Service\Fork\ForkReasoning;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): the reasoning switch reaches the request body, and only when off.
 */
final class ForkReasoningTest extends TestCase
{
    public function testOnSendsNothingForEitherDialect(): void
    {
        self::assertSame([], ForkReasoning::fields(AiProvider::Ollama, true));
        self::assertSame([], ForkReasoning::fields(AiProvider::OpenAi, true));
    }

    public function testOffIsSpelledInEachDialect(): void
    {
        self::assertSame(['think' => false], ForkReasoning::fields(AiProvider::Ollama, false));
        self::assertSame(
            ['chat_template_kwargs' => ['enable_thinking' => false]],
            ForkReasoning::fields(AiProvider::OpenAi, false),
        );
    }

    public function testAnOpenAiCompletionCarriesTheSwitchWhenOff(): void
    {
        $bodies = [];
        $client = new OpenAiClient($this->recording($bodies, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi.']]],
        ])), new NullLogger(), $this->reasoning(false));

        $client->chat(new AiConnection('http://192.0.2.1:8000/v1', null, AiProvider::OpenAi), 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(['enable_thinking' => false], $bodies[0]['chat_template_kwargs'] ?? null);
        self::assertSame('m', $bodies[0]['model']);
    }

    public function testAnOpenAiStreamCarriesTheSwitchWhenOff(): void
    {
        $bodies = [];
        $client = new OpenAiClient(
            $this->recording($bodies, 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hi.']]]]) . "\n\ndata: [DONE]\n\n"),
            new NullLogger(),
            $this->reasoning(false),
        );

        $stream = $client->chatStream(new AiConnection('http://192.0.2.1:8000/v1', null, AiProvider::OpenAi), 'm', [['role' => 'user', 'content' => 'hi']]);
        iterator_to_array($stream, false);

        self::assertTrue($stream->getReturn()->succeeded);
        self::assertSame(['enable_thinking' => false], $bodies[0]['chat_template_kwargs'] ?? null);
        self::assertTrue($bodies[0]['stream']);
    }

    public function testAnOpenAiCompletionIsUnchangedWhenOn(): void
    {
        $bodies = [];
        $client = new OpenAiClient($this->recording($bodies, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi.']]],
        ])), new NullLogger(), $this->reasoning(true));

        $client->chat(new AiConnection('http://192.0.2.1:8000/v1', null, AiProvider::OpenAi), 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertArrayNotHasKey('chat_template_kwargs', $bodies[0]);
    }

    public function testOllamaIsToldNotToThinkEvenBehindAKey(): void
    {
        $bodies = [];
        $client = (new OllamaClient(
            $this->recording($bodies, json_encode(['message' => ['role' => 'assistant', 'content' => 'Hi.'], 'done' => true])),
            new NullLogger(),
            $this->reasoning(false),
        ))->authorised(new AiConnection('http://192.0.2.1:11434', 'a-key'));

        $client->chat('http://192.0.2.1:11434', 'm', [['role' => 'user', 'content' => 'hi']]);

        self::assertFalse($bodies[0]['think'] ?? null);
    }

    private function reasoning(bool $enabled): ForkReasoning
    {
        $stub = $this->createStub(ForkReasoning::class);
        $stub->method('fieldsFor')->willReturnCallback(
            static fn (AiProvider $provider): array => ForkReasoning::fields($provider, $enabled),
        );

        return $stub;
    }

    /** @param list<array<string, mixed>> $bodies */
    private function recording(array &$bodies, string|false $answer): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $answer): MockResponse {
            $bodies[] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse((string) $answer);
        });
    }
}
