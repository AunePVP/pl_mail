<?php

declare(strict_types=1);

namespace App\Tests\Service\Fork;

use App\Domain\Enum\Ai\PromptSlot;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Ai\AiSettings;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\OpenAiClient;
use App\Service\Ai\PromptLibrary;
use App\Service\Ai\WritingAssistant;
use App\Service\Fork\ForkNameGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): names in a draft come back from "shorter", "more formal" and
 * "proofread" letter for letter, and the composer is told when they do not.
 */
final class ForkNameGuardTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The case it was written for: the signature's "Strauß", and the invented "Straussler". */
    public function testAChangedSurnameIsReported(): void
    {
        $draft = "Sehr geehrte Frau Strauss,\n\nanbei die Unterlagen.";

        self::assertSame(['Strauss'], ForkNameGuard::missing(WritingTask::Formal, $draft, "Sehr geehrte Frau Strauß,\n\nanbei erhalten Sie die Unterlagen."));
        self::assertSame(['Strauss'], ForkNameGuard::missing(WritingTask::Formal, $draft, "Sehr geehrte Frau Straussler,\n\nanbei erhalten Sie die Unterlagen."));
        self::assertSame([], ForkNameGuard::missing(WritingTask::Formal, $draft, "Sehr geehrte Frau Strauss,\n\nanbei erhalten Sie die Unterlagen."));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function drafts(): iterable
    {
        yield 'title between' => ['Hallo Herr Dr. Meier, kurz zur Sache.', 'Sehr geehrter Herr Dr. Maier, kurz zur Sache.', ['Meier']];
        yield 'first and last name' => ["Lieber Herr Max Müller,\ndanke.", "Sehr geehrter Herr Müller,\nvielen Dank.", ['Max']];
        yield 'name in the body' => ['Ich habe mit Herrn Schulz gesprochen.', 'Ich habe mit Herrn Schultz gesprochen.', ['Schulz']];
        yield 'first name greeting' => ['Hallo Peter, passt das?', 'Lieber Peter, passt Ihnen das?', []];
        yield 'not a name' => ['Hallo Team, morgen geht es los.', 'Sehr geehrte Damen und Herren, morgen beginnt es.', []];
        yield 'a noun after the name is no name' => ['Bitte Frau Strauss Bescheid geben.', 'Bitte informieren Sie Frau Strauss.', []];
        yield 'english' => ['Dear Mrs. O\'Neill, thanks.', 'Dear Mrs. ONeill, thank you.', ["O'Neill"]];
        yield 'nothing addressed' => ['Die Pläne kommen morgen.', 'Die Pläne werden morgen übersandt.', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('drafts')]
    public function testOnlyNamesAreLookedFor(string $draft, string $answer, array $expected): void
    {
        self::assertSame($expected, ForkNameGuard::missing(WritingTask::Formal, $draft, $answer));
    }

    public function testAReplyIsNotChecked(): void
    {
        self::assertSame([], ForkNameGuard::missing(WritingTask::Reply, 'Frau Strauss fragt nach.', 'Sehr geehrte Frau Strauß,'));
        self::assertSame(['Strauss'], ForkNameGuard::missing(WritingTask::Proofread, 'Hallo Frau Strauss,', 'Hallo Frau Strauß,'));
        self::assertSame(['Strauss'], ForkNameGuard::missing(WritingTask::Shorten, 'Hallo Frau Strauss,', 'Hallo Frau Strauß,'));
    }

    /** The rule rides along after the administrator's own wording, and only on the three rewrites. */
    public function testTheRuleIsSentForRewritesEvenUnderAnAdministratorsPrompt(): void
    {
        $sent      = null;
        $assistant = $this->assistant($sent);

        $settings = $this->settings->currentOrDefault();
        $settings->prompts->put(PromptSlot::Formal, 'Make it sound like a court.');
        $this->em->persist($settings);
        $this->em->flush();

        $assistant->write(new User(), WritingTask::Formal, 'Hallo Frau Strauss, passt das?', 'Anna Strauß');

        self::assertIsArray($sent);
        self::assertStringContainsString('Make it sound like a court.', $sent['messages'][0]['content']);
        self::assertStringContainsString(ForkNameGuard::RULE, $sent['messages'][0]['content']);
        self::assertStringNotContainsString(ForkNameGuard::RULE, $sent['messages'][1]['content']);

        $assistant->write(new User(), WritingTask::Reply, '', 'Wann haben Sie geöffnet?');

        self::assertStringNotContainsString(ForkNameGuard::RULE, $sent['messages'][0]['content']);
    }

    /**
     * @param array<string, mixed>|null $sent
     */
    private function assistant(?array &$sent): WritingAssistant
    {
        $settings = new AiSettings();
        $settings->isEnabled          = true;
        $settings->baseUrl            = 'http://10.0.0.5:11434';
        $settings->chatModel          = 'llama3.1:8b';
        $settings->writingHelpEnabled = true;

        $this->em->persist($settings);
        $this->em->flush();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $body = $options['body'] ?? '';
            $sent = is_string($body) ? json_decode($body, true) : null;

            return new MockResponse(json_encode(['message' => ['content' => 'an answer']]));
        });

        $ai = new AiAssistant(
            $this->settings,
            new OllamaClient($http, new NullLogger()),
            new OpenAiClient($http, new NullLogger()),
            new AiCallRecorder($this->createStub(Connection::class), new NullLogger()),
            new NullLogger(),
        );

        return new WritingAssistant($ai, new AiPermissions($ai), new PromptLibrary($this->settings));
    }
}
