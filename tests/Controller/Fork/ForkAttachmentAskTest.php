<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Ai\AiProvider;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): "Anhänge fragen" — the chat about one mail's attachments.
 * The model is a mock; what it was sent is what most of these look at.
 */
final class ForkAttachmentAskTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    private string $answer = 'Der Betrag ist 123,45 EUR (S. 1).';

    /** @var list<array<string, mixed>> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body          = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];
            $this->asked[] = $body;

            if (true === str_contains($url, '/chat/completions')) {
                $events = '';

                foreach (mb_str_split($this->answer, 5) as $piece) {
                    $events .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $piece]]]]) . "\n\n";
                }

                return new MockResponse($events . "data: [DONE]\n\n", ['response_headers' => ['Content-Type: text/event-stream']]);
            }

            return new MockResponse(ForkSearchInsightTest::ollamaStream($this->answer));
        }));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheDialogListsTheAttachments(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $this->attach($message, 'notiz.txt', 'text/plain', 'Hallo');
        $this->attach($message, 'plan.zip', 'application/zip', 'PK');

        $this->client->request('GET', sprintf('/mail/message/%d/fork/ask', $message->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-fork-attachment-ask]');
        self::assertSelectorCount(2, 'input[type=checkbox][data-fork--attachment-ask-target=part]');
        self::assertSelectorTextContains('[data-fork-attachment-ask]', 'plan.zip');
    }

    public function testWithoutTheSwitchTheDialogSaysSo(): void
    {
        $message = $this->seedMessage();
        $this->attach($message, 'notiz.txt', 'text/plain', 'Hallo');

        $this->client->request('GET', sprintf('/mail/message/%d/fork/ask', $message->id));

        self::assertSelectorExists('[data-fork-attachment-ask-off]');
        self::assertSelectorNotExists('[data-fork-attachment-ask]');
    }

    public function testTheButtonStandsBesideTheAttachments(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $this->attach($message, 'notiz.txt', 'text/plain', 'Hallo');

        $this->client->request('GET', sprintf('/mail/thread/%d', $message->thread?->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-fork-attachment-ask-button]');
    }

    public function testATurnReadsTheDocumentsAndAnswers(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $pdf     = $this->attach($message, 'rechnung.pdf', 'application/pdf', self::pdf('Rechnung Nummer 4711 Betrag 123,45 EUR zahlbar bis 30.11.2026 ohne Abzug an die Ingenieurgesellschaft'));
        $docx    = $this->attach($message, 'angebot.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', self::docx(['Angebot für die Brücke', 'Summe 9.870 EUR']));
        $csv     = $this->attach($message, 'liste.csv', 'text/csv', "Position;Preis\nStahl;500\n");
        $zip     = $this->attach($message, 'plaene.zip', 'application/zip', 'PK');

        $frames = $this->turn($message, 'Was kostet das?');
        $types  = array_column($frames, 'type');

        self::assertSame('conversation', $types[0]);
        self::assertSame('done', $types[array_key_last($types)]);
        self::assertSame($this->answer, $this->frame($frames, 'done')['answer']);

        $states = array_column($this->frame($frames, 'documents')['items'], 'status', 'id');
        self::assertSame('text', $states[$pdf->id]);
        self::assertSame('text', $states[$docx->id]);
        self::assertSame('text', $states[$csv->id]);
        self::assertSame('unsupported', $states[$zip->id]);
        self::assertSame(1, array_column($this->frame($frames, 'documents')['items'], 'pages', 'id')[$pdf->id]);

        $opening = $this->sent()[1]['content'];
        self::assertIsString($opening);
        self::assertStringContainsString('--- Seite 1 ---', $opening);
        self::assertStringContainsString('123,45 EUR', $opening);
        self::assertStringContainsString("Angebot für die Brücke\nSumme 9.870 EUR", $opening);
        self::assertStringContainsString('Stahl;500', $opening);
        self::assertStringContainsString('name="plaene.zip"', $opening);
        self::assertStringContainsString('Question: Was kostet das?', $opening);
    }

    public function testAFollowUpRepeatsTheOpeningAndKeepsTheChoice(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $first   = $this->attach($message, 'a.txt', 'text/plain', 'Erster Text');
        $this->attach($message, 'b.txt', 'text/plain', 'Zweiter Text');

        $frames       = $this->turn($message, 'Worum geht es?', parts: (string) $first->id);
        $conversation = (string) $this->frame($frames, 'conversation')['id'];
        $opening      = $this->sent()[1]['content'];

        self::assertStringNotContainsString('Zweiter Text', $opening);

        $this->asked = [];
        // The choice was made at the first question; a later one cannot widen it.
        $this->turn($message, 'Und sonst?', $conversation, '');
        $sent = $this->sent();

        self::assertSame($opening, $sent[1]['content']);
        self::assertSame('assistant', $sent[2]['role']);
        self::assertSame(['role' => 'user', 'content' => 'Und sonst?'], $sent[3]);
    }

    public function testAPhotoGoesAsAPictureToAnOpenAiEndpoint(): void
    {
        $this->switchOn(AiProvider::OpenAi);
        $message = $this->seedMessage();

        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($image);
        $this->attach($message, 'foto.png', 'image/png', (string) ob_get_clean());

        $this->turn($message, 'Was ist darauf?');

        $opening = $this->sent()[1]['content'];
        self::assertIsArray($opening);
        $types = array_column($opening, 'type');
        self::assertContains('image_url', $types);
        self::assertStringStartsWith('data:image/jpeg;base64,', $opening[array_search('image_url', $types, true)]['image_url']['url']);
    }

    /** A PDF without a text layer is a scan: its pages go as pictures, or it says so. */
    public function testAScanGoesAsPagesToAnOpenAiEndpoint(): void
    {
        $this->switchOn(AiProvider::OpenAi);
        $message = $this->seedMessage();
        $scan    = $this->attach($message, 'scan.pdf', 'application/pdf', self::pdf(''));

        $frames = $this->turn($message, 'Was steht drin?');

        $item = $this->frame($frames, 'documents')['items'][0];
        self::assertSame($scan->id, $item['id']);
        self::assertSame('images', $item['status']);
        self::assertContains('image_url', array_column($this->sent()[1]['content'], 'type'));
    }

    public function testAScanIsUnreadableForOllama(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $this->attach($message, 'scan.pdf', 'application/pdf', self::pdf(''));

        $frames = $this->turn($message, 'Was steht drin?');

        self::assertSame('scan', $this->frame($frames, 'documents')['items'][0]['status']);
        self::assertSame([], $this->asked);
    }

    public function testAPhotoIsUnreadableForOllama(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $this->attach($message, 'foto.png', 'image/png', 'not even a png');

        $frames = $this->turn($message, 'Was ist darauf?');

        self::assertSame('unsupported', $this->frame($frames, 'documents')['items'][0]['status']);
        self::assertSame('unreadable', $this->frame($frames, 'error')['reason']);
        self::assertSame([], $this->asked);
    }

    public function testSomebodyElsesMailIsNotOpened(): void
    {
        $this->switchOn();
        $message = $this->seedMessage();
        $this->attach($message, 'notiz.txt', 'text/plain', 'Geheim');

        $this->client->loginUser($this->seedUser());
        $this->client->request('GET', sprintf('/mail/message/%d/fork/ask', $message->id));

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function turn(Message $message, string $question, string $conversation = '', string $parts = ''): array
    {
        $token = $this->token();

        ob_start();

        try {
            $this->client->request(
                'POST',
                sprintf('/mail/message/%d/fork/ask', $message->id),
                parameters: ['message' => $question, 'conversation' => $conversation, 'parts' => $parts],
                server: ['HTTP_X_CSRF_TOKEN' => $token],
            );
        } finally {
            $escaped = (string) ob_get_clean();
        }

        self::assertResponseIsSuccessful();

        $body = '' !== $escaped ? $escaped : (string) $this->client->getInternalResponse()->getContent();

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(explode("\n", $body)),
        ));
    }

    /**
     * @param list<array<string, mixed>> $frames
     *
     * @return array<string, mixed>
     */
    private function frame(array $frames, string $type): array
    {
        foreach ($frames as $frame) {
            if ($type === $frame['type']) {
                return $frame;
            }
        }

        self::fail(sprintf('No "%s" frame in %s', $type, json_encode($frames)));
    }

    /** @return list<array{role: string, content: mixed}> what the last model call was sent */
    private function sent(): array
    {
        $last = end($this->asked) ?: self::fail('The model was not asked.');

        return $last['messages'];
    }

    private function token(): string
    {
        $this->client->request('GET', '/mail/inbox');

        $container = static::getContainer();
        $stack     = $container->get('request_stack');
        $stack->push($this->client->getRequest());

        try {
            return (string) $container->get('security.csrf.token_manager')->getToken('ajax')->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function switchOn(AiProvider $provider = AiProvider::Ollama): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                 = new AiSettings();
        $settings->isEnabled      = true;
        $settings->provider       = $provider;
        $settings->baseUrl        = AiProvider::OpenAi === $provider ? 'http://model-host.invalid:8000/v1' : 'http://model-host.invalid:11434';
        $settings->chatModel      = 'qwen3:30b';
        $settings->summaryEnabled = true;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function seedMessage(): Message
    {
        $at = new DateTimeImmutable('-1 day');

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Rechnung';
        $thread->normalizedSubject = 'rechnung';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $at;
        $thread->category          = MessageCategory::Updates;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->addLabel($this->inbox);
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = 'Rechnung';
        $message->fromAddress    = 'buchhaltung@example.test';
        $message->fromName       = 'Buchhaltung';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->seenAt         = $at;
        $message->flags          = [];
        $message->hasAttachments = true;
        $message->bodyText       = 'Anbei die Rechnung.';

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function attach(Message $message, string $filename, string $type, string $content): MessagePart
    {
        $storage = self::getContainer()->get(AttachmentStorageHelper::class);

        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = $type;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($content);
        $part->storagePath = $storage->store((int) $this->account->id, 0, random_int(1, PHP_INT_MAX), $filename, $content);
        $part->isInline    = false;

        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    /** A one-page PDF with a text layer, offsets and all. */
    private static function pdf(string $text): string
    {
        $stream  = sprintf("BT /F1 12 Tf 50 750 Td (%s) Tj ET", $text);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf      .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $object);
        }

        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /** @param list<string> $paragraphs */
    private static function docx(array $paragraphs): string
    {
        $file = tempnam(sys_get_temp_dir(), 'docx');
        $zip  = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);

        $body = implode('', array_map(
            static fn (string $p): string => sprintf('<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="720"/></w:tabs></w:pPr><w:r><w:t xml:space="preserve">%s</w:t></w:r></w:p>', htmlspecialchars($p, \ENT_XML1)),
            $paragraphs,
        ));

        $zip->addFromString('word/document.xml', sprintf('<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>%s</w:body></w:document>', $body));
        $zip->close();

        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }
}
