<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Calendar\CalendarProvisioner;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): the search chat behind the "KI-Suche" button. The model is a
 * mock: its plan (the call whose system prompt asks what to search for) and
 * its answer are what the test sets. Both are streamed.
 */
final class ForkSearchChatTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    private string $plan = '{"queries":[],"question":""}';
    private string $answer = '{"answer":"","important":[]}';

    /** @var list<array<string, mixed>> what the model was sent, plan and answer alike */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = self::getContainer();
        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body          = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];
            $this->asked[] = $body;

            $system = (string) ($body['messages'][0]['content'] ?? '');

            return new MockResponse(ForkSearchInsightTest::ollamaStream(
                true === str_contains($system, 'Decide what to search for') ? $this->plan : $this->answer,
            ));
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

    public function testATurnSearchesWhatTheModelWroteAndAnswersFromIt(): void
    {
        $this->switchOn();
        $wanted = $this->seedThread('Buchungsbestätigung Fernbus Musterstadt', '-1 day');
        $this->seedThread('Newsletter mit Angeboten', '-2 hours');

        $this->plan   = '{"queries":["buchungsbestätigung"],"question":"Wann fahre ich mit dem Bus nach Musterstadt?"}';
        $this->answer = sprintf('{"answer":"Am Donnerstag um 16:25.","important":[%d, 999999]}', $wanted->id);

        $frames = $this->turn('wann ist meine busfahrt nach musterstadt');
        $types  = array_column($frames, 'type');

        self::assertSame('conversation', $types[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $frames[0]['id']);
        self::assertSame(['buchungsbestätigung'], $this->frame($frames, 'search')['queries']);
        self::assertSame([$wanted->id], array_column($this->frame($frames, 'results')['items'], 'id'));
        self::assertContains('token', $types);
        self::assertSame('done', $types[array_key_last($types)]);

        $done = $this->frame($frames, 'done');
        self::assertSame('Am Donnerstag um 16:25.', $done['answer']);
        // The id the model invented is not linked.
        self::assertSame([$wanted->id], array_column($done['important'], 'id'));
        self::assertSame(sprintf('/mail/thread/%d', $wanted->id), $done['important'][0]['url']);

        // The answer read the result the search found, and the question as it stands on its own.
        $read = json_decode((string) end($this->asked)['messages'][1]['content'], true);
        self::assertSame('Wann fahre ich mit dem Bus nach Musterstadt?', $read['question']);
        self::assertSame('Buchungsbestätigung Fernbus Musterstadt', $read['results'][0]['subject']);
    }

    public function testAFollowUpCarriesTheConversation(): void
    {
        $this->switchOn();
        $wanted = $this->seedThread('Buchungsbestätigung Fernbus Musterstadt', '-1 day');

        $this->plan   = '{"queries":["buchungsbestätigung"],"question":"Wann fahre ich hin?"}';
        $this->answer = '{"answer":"Am Donnerstag.","important":[]}';
        $id           = (string) $this->turn('wann fahre ich nach musterstadt')[0]['id'];

        // Nothing new to search: the results of the first turn are read again.
        $this->plan   = '{"queries":[],"question":"Wann fahre ich von Musterstadt zurück?"}';
        $this->answer = sprintf('{"answer":"Am Montag.","important":[%d]}', $wanted->id);
        $this->asked  = [];

        $frames = $this->turn('und zurück?', $id);

        self::assertSame($id, $frames[0]['id']);
        self::assertSame([], $this->frame($frames, 'search')['queries']);
        self::assertNotContains('results', array_column($frames, 'type'));
        self::assertSame([$wanted->id], array_column($this->frame($frames, 'done')['important'], 'id'));

        // The planner saw the first exchange.
        $planned = $this->planned();
        self::assertContains('wann fahre ich nach musterstadt', $planned);
        self::assertContains('Am Donnerstag.', $planned);
    }

    /** The conversation id is no key on its own: another user's id starts afresh. */
    public function testAConversationIsTheUsersOwn(): void
    {
        $this->switchOn();
        $this->seedThread('Buchungsbestätigung Fernbus Musterstadt', '-1 day');

        $this->plan   = '{"queries":["buchungsbestätigung"],"question":"Wann fahre ich?"}';
        $this->answer = '{"answer":"Am Donnerstag.","important":[]}';
        $id           = (string) $this->turn('wann fahre ich')[0]['id'];

        $other = $this->seedUser();
        $this->client->loginUser($other);
        $this->asked = [];
        $this->turn('und zurück?', $id);

        $planned = $this->planned();
        self::assertNotContains('Am Donnerstag.', $planned);
    }

    public function testWithoutTheSwitchNothingIsAsked(): void
    {
        $frames = $this->turn('wann fahre ich');

        self::assertSame([['type' => 'error']], $frames);
        self::assertSame([], $this->asked);
    }

    public function testWithoutTheTokenItIsRefused(): void
    {
        $this->switchOn();
        $this->client->request('POST', '/mail/search/fork/chat', ['message' => 'wann fahre ich']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->asked);
    }

    public function testTheButtonOpensTheChatInsteadOfTheResults(): void
    {
        $this->switchOn();
        $this->seedThread('Buchungsbestätigung Fernbus Musterstadt', '-1 day');

        $this->client->request('GET', '/mail/search?q=buchungsbest%C3%A4tigung');
        self::assertSelectorExists('[data-fork-ai-search]');
        self::assertSelectorNotExists('[data-fork-search-chat]');
        self::assertSelectorExists('#fork-search-answer');
        self::assertStringContainsString('Fernbus Musterstadt', (string) $this->client->getResponse()->getContent());

        // The sentence is not searched: no list, no panels, nothing asked of a model.
        $this->asked = [];
        $this->client->request('GET', '/mail/search?q=buchungsbest%C3%A4tigung&fork_ai=1');
        self::assertSelectorExists('[data-fork-search-chat]');
        self::assertSelectorNotExists('#fork-search-answer');
        self::assertSelectorNotExists('#fork-search-trips');
        self::assertStringNotContainsString('Fernbus Musterstadt', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->asked);
    }

    /** "was steht die Woche an" names no title: the period brings the calendar, every entry in it. */
    public function testAPeriodBringsEveryCalendarEntryInIt(): void
    {
        $this->switchOn();
        $this->seedEvent('Zahnarzt Dr. Muster', '+2 days 10:00');
        $this->seedEvent('Konzert im Stadtpark', '+40 days 20:00');

        $today        = new DateTimeImmutable('today');
        $this->plan   = sprintf(
            '{"queries":["termin"],"question":"Was steht diese Woche an?","period":{"from":"%s","to":"%s"}}',
            $today->format('Y-m-d'),
            $today->modify('+6 days')->format('Y-m-d'),
        );
        $this->answer = '{"answer":"Übermorgen der Zahnarzt.","important":[]}';

        $this->turn('was steht die woche an');

        $read = json_decode((string) end($this->asked)['messages'][1]['content'], true);
        self::assertSame(['Zahnarzt Dr. Muster'], array_column($read['calendar'], 'title'));
        self::assertStringStartsWith($today->format('Y-m-d'), (string) $read['period']['from']);
    }

    /** No mail was searched, and the mail an entry was read from is still linked. */
    public function testTheMailOfACalendarEntryCanBeLinked(): void
    {
        $this->switchOn();
        $booking = $this->seedThread('Ihre Buchung beim Zahnarzt', '-3 days');
        $event   = $this->seedEvent('Zahnarzt Dr. Muster', '+2 days 10:00');
        $this->linkEvent($event, $booking);

        $today        = new DateTimeImmutable('today');
        $this->plan   = sprintf('{"queries":[],"question":"Was steht an?","period":{"from":"%s","to":"%s"}}', $today->format('Y-m-d'), $today->modify('+6 days')->format('Y-m-d'));
        $this->answer = sprintf('{"answer":"Übermorgen der Zahnarzt.","important":[%d]}', $booking->id);

        $frames = $this->turn('was steht an');

        $read = json_decode((string) end($this->asked)['messages'][1]['content'], true);
        self::assertSame([$booking->id], $read['calendar'][0]['mails']);
        self::assertSame([$booking->id], array_column($this->frame($frames, 'done')['important'], 'id'));
    }

    public function testAPeriodThatDoesNotParseIsNone(): void
    {
        $this->switchOn();
        $this->plan   = '{"queries":["termin"],"question":"Was war gestern?","period":{"from":"gestern","to":"heute"}}';
        $this->answer = '{"answer":"Nichts.","important":[]}';

        $this->turn('was war gestern');

        $read = json_decode((string) end($this->asked)['messages'][1]['content'], true);
        self::assertNull($read['period']);
    }

    /** "was habe ich im Dezember 2024 bestellt": days that are over narrow the mail, not just the calendar. */
    public function testAPastPeriodNarrowsTheSearches(): void
    {
        $this->switchOn();
        $old = $this->seedThread('Bestellung Schreibtischlampe', '-400 days');
        $this->seedThread('Bestellung Kaffeebohnen', '-1 day');

        $day          = new DateTimeImmutable('-400 days');
        $this->plan   = sprintf(
            '{"queries":["bestellung"],"question":"Was habe ich damals bestellt?","period":{"from":"%s","to":"%s"}}',
            $day->modify('-3 days')->format('Y-m-d'),
            $day->modify('+3 days')->format('Y-m-d'),
        );
        $this->answer = '{"answer":"Eine Lampe.","important":[]}';

        $frames = $this->turn('was habe ich damals bestellt');

        self::assertSame([$old->id], array_column($this->frame($frames, 'results')['items'], 'id'));
    }

    /** "das erste Mal": each search's oldest results, which the newest forty do not reach. */
    public function testTheFirstTimeReadsTheOldestResults(): void
    {
        $this->switchOn();
        $first = $this->seedThread('Fernbus Buchung 1', '-60 days');

        foreach (range(2, 45) as $n) {
            $this->seedThread('Fernbus Buchung ' . $n, sprintf('-%d days', 60 - $n));
        }

        $this->plan   = '{"queries":["fernbus"],"question":"Wann bin ich das erste Mal Fernbus gefahren?","oldest":true}';
        $this->answer = '{"answer":"Vor zwei Monaten.","important":[]}';

        $ids = array_column($this->frame($this->turn('wann bin ich das erste mal fernbus gefahren'), 'results')['items'], 'id');
        self::assertSame($first->id, $ids[0]);

        // The newest forty alone would not have reached it.
        $this->plan = '{"queries":["fernbus"],"question":"Welche Fernbusbuchungen habe ich?"}';
        $ids        = array_column($this->frame($this->turn('welche fernbusbuchungen habe ich'), 'results')['items'], 'id');
        self::assertNotContains($first->id, $ids);
    }

    /** A first question with no searches from the model searches its own words. */
    public function testAFirstQuestionAlwaysSearches(): void
    {
        $this->switchOn();
        $wanted = $this->seedThread('Busfahrt Musterstadt bestätigt', '-1 day');

        $this->plan   = '{"queries":[],"question":"Welche Busfahrt habe ich diese Woche?"}';
        $this->answer = '{"answer":"Eine.","important":[]}';

        $frames = $this->turn('welche busfahrt habe ich diese woche');

        self::assertSame(['busfahrt'], $this->frame($frames, 'search')['queries']);
        self::assertSame([$wanted->id], array_column($this->frame($frames, 'results')['items'], 'id'));
    }

    /** …unless it names a time: the calendar answers that, and its words would find any mail that says "steht". */
    public function testAQuestionAboutAPeriodNeedsNoSearchOfItsOwnWords(): void
    {
        $this->switchOn();
        $today        = new DateTimeImmutable('today');
        $this->plan   = sprintf('{"queries":[],"question":"Was steht diese Woche an?","period":{"from":"%s","to":"%s"}}', $today->format('Y-m-d'), $today->modify('+5 days')->format('Y-m-d'));
        $this->answer = '{"answer":"Nichts.","important":[]}';

        self::assertSame([], $this->frame($this->turn('was steht die woche an'), 'search')['queries']);
    }

    /**
     * One search of alternatives finds the mail in either language: the
     * order whose mail says "Order … confirmed" was missing when the chat
     * searched "bestellung" and "bestellt".
     */
    public function testASearchOfAlternativesFindsEitherWord(): void
    {
        $this->switchOn();
        $german  = $this->seedThread('Bestellung bestätigt: Schreibtischlampe', '-3 days');
        $english = $this->seedThread('Order AB-1234 confirmed', '-2 days');
        $this->seedThread('Newsletter mit Angeboten', '-1 day');

        $this->plan   = '{"queries":["bestellung or bestellt or bestellbestätigung or auftragsbestätigung or gekauft or order or ordered or purchase"],"question":"Was habe ich zuletzt bestellt?"}';
        $this->answer = '{"answer":"Zwei Sachen.","important":[]}';

        $frames = $this->turn('was habe ich als letztes bestellt');

        // Newest first, the newsletter not among them.
        self::assertSame([$english->id, $german->id], array_column($this->frame($frames, 'results')['items'], 'id'));
        // The page names the first few alternatives, not all of them.
        self::assertSame(['bestellung', 'bestellt', 'bestellbestätigung', 'auftragsbestätigung', 'gekauft', 'order', '…'], $this->frame($frames, 'search')['queries']);
    }

    /** A plan the model does not stop writing is cut off, and the message's own words are searched. */
    public function testAPlanThatDoesNotEndIsCutOff(): void
    {
        $this->switchOn();
        $wanted = $this->seedThread('Jacke bestellt', '-1 day');

        $this->plan   = '{"queries":["jacke or jacket' . str_repeat(' or coat', 1000);
        $this->answer = '{"answer":"Eine Jacke.","important":[]}';

        $frames = $this->turn('habe ich eine jacke bestellt');

        self::assertSame('done', $frames[array_key_last($frames)]['type']);
        self::assertContains($wanted->id, array_column($this->frame($frames, 'results')['items'], 'id'));
    }

    /** The button is there regardless (asking would cost every page a query); the page says why nothing happens. */
    public function testWithoutTheSwitchThePageSaysSo(): void
    {
        $this->client->request('GET', '/mail/search?q=rechnung&fork_ai=1');

        self::assertSelectorExists('[data-fork-ai-search]');
        self::assertSelectorNotExists('[data-fork-search-chat]');
        self::assertSelectorExists('[data-fork-search-chat-off]');
        self::assertSame([], $this->asked);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function turn(string $message, string $conversation = ''): array
    {
        $token = $this->token();

        ob_start();

        try {
            $this->client->request(
                'POST',
                '/mail/search/fork/chat',
                parameters: ['message' => $message, 'conversation' => $conversation],
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

    /**
     * What the planner, the first call of the last turn, was sent.
     *
     * @return list<string>
     */
    private function planned(): array
    {
        $first = $this->asked[0] ?? self::fail('The model was not asked.');

        return array_column($first['messages'], 'content');
    }

    /** As ThreadSummaryTest::token(): a page first, then the token against that request's session. */
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

    private function switchOn(): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                 = new AiSettings();
        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->chatModel      = 'qwen3:30b';
        $settings->summaryEnabled = true;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function seedEvent(string $title, string $when): CalendarEvent
    {
        $event           = new CalendarEvent();
        $event->calendar = self::getContainer()->get(CalendarProvisioner::class)->defaultFor($this->user);
        $event->usr      = $this->user;
        $event->uid      = uniqid('fork-', true) . '@plmail';
        $event->title    = $title;
        $event->startsAt = new DateTimeImmutable($when);
        $event->endsAt   = $event->startsAt->modify('+1 hour');

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function linkEvent(CalendarEvent $event, MessageThread $thread): void
    {
        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $thread->messages->first() ?: null;
        $link->extractor = 'structuredData';
        $link->dedupKey  = uniqid('fork-', true);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function seedThread(string $subject, string $when): MessageThread
    {
        $at = new DateTimeImmutable($when);

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
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
        $message->subject        = $subject;
        $message->fromAddress    = 'noreply@example.test';
        $message->fromName       = 'Example';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->seenAt         = $at;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->bodyText       = 'Text zu: ' . $subject;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }
}
