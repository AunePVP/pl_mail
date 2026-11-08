<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Fork;

use App\Entity\Fork\ForkMessageProject;
use App\Entity\User\User;
use App\Jmap\Method\Mail\EmailChangesMethod;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\JmapProcessor;
use App\Jmap\Protocol\JmapRequest;
use App\Jmap\Session\SessionBuilder;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Service\Fork\Projektakte\ForkProjectAssigner;
use App\Service\Fork\Projektakte\ForkProjektakteClient;
use App\Service\Mail\MailChangeRecorder;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Support\Fork\FakeProjektakte;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * FORK (FORK.md 27): what Projektakte reads — the capability in the Session,
 * the property on Email/get, and every change to it in Email/changes, which is
 * the only way Projektakte learns that someone filed a mail.
 */
final class ForkProjektakteEmailTest extends JmapTestCase
{
    private const string URN = 'urn:plmail:params:jmap:projektakte';
    private const string PROPERTY = 'plmail:projektakte';

    private FakeProjektakte $projektakte;
    private ForkProjectAssigner $assigner;
    private EmailGetMethod $get;

    protected function setUp(): void
    {
        $this->projektakte = new FakeProjektakte();

        parent::setUp();

        // Built here rather than taken from the container: seeding has already
        // made the container's own client, with the container's HTTP client.
        $container      = self::getContainer();
        $this->assigner = new ForkProjectAssigner(
            $this->em,
            $container->get(ForkMessageProjectRepository::class),
            new ForkProjektakteClient($this->projektakte->client(), new ArrayAdapter(), new NullLogger(), $this->projektakte->url, FakeProjektakte::TOKEN),
            $container->get(MailChangeRecorder::class),
        );
        $this->get      = $container->get(EmailGetMethod::class);
    }

    public function testTheSessionNamesTheUrnAtTheTopAndOnEveryAccount(): void
    {
        $second  = $this->secondAccount();
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        self::assertArrayHasKey(self::URN, $session['capabilities']);
        self::assertArrayHasKey(self::URN, $session['accounts'][$this->accountId()]['accountCapabilities']);
        self::assertArrayHasKey(self::URN, $session['accounts'][(string) $second->id]['accountCapabilities']);
        self::assertSame('{}', json_encode($session['capabilities'][self::URN]));
    }

    /** Projektakte lists the URN in `using`; a server that did not know it would refuse the whole request. */
    public function testARequestUsingTheUrnIsAnswered(): void
    {
        self::assertContains(Capability::PROJEKTAKTE, Capability::SUPPORTED);

        $message = $this->receivedMessage();
        $this->assigner->assign($message, $this->user, 'BS-0000-0001');

        $response = self::getContainer()->get(JmapProcessor::class)->process(JmapRequest::fromArray([
            'using'       => [Capability::CORE, Capability::MAIL, self::URN],
            'methodCalls' => [['Email/get', ['accountId' => $this->accountId(), 'ids' => [(string) $message->id], 'properties' => ['id', self::PROPERTY]], 'a']],
        ]), $this->user);

        [$name, $result] = $response->methodResponses[0];

        self::assertSame('Email/get', $name);
        self::assertSame('BS-0000-0001', $result['list'][0][self::PROPERTY]['project']);
    }

    public function testEmailGetAnswersNullAssignedOrNone(): void
    {
        $open     = $this->receivedMessage();
        $assigned = $this->receivedMessage();
        $none     = $this->receivedMessage();

        $this->assigner->assign($assigned, $this->user, 'BS-0000-0001');
        $this->assigner->markNone($none, $this->user);

        $byId = $this->emails([$open->id, $assigned->id, $none->id], ['id', self::PROPERTY]);

        self::assertNull($byId[(string) $open->id][self::PROPERTY]);

        $value = $byId[(string) $assigned->id][self::PROPERTY];
        self::assertSame('assigned', $value['status']);
        self::assertSame('BS-0000-0001', $value['project']);
        self::assertSame('Rathaus Umbau', $value['projectName']);
        self::assertSame('Jmap Fixture', $value['assignedBy'], 'the name, not the login');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $value['assignedAt']);

        self::assertSame(
            ['status' => 'none', 'project' => null, 'projectName' => ''],
            array_intersect_key($byId[(string) $none->id][self::PROPERTY], ['status' => 1, 'project' => 1, 'projectName' => 1]),
        );
    }

    /** Opt-in by name, like replyFrom: a client that does not ask does not get it. */
    public function testThePropertyIsOnlyThereWhenAskedFor(): void
    {
        $message = $this->receivedMessage();
        $this->assigner->assign($message, $this->user, 'BS-0000-0001');

        self::assertArrayNotHasKey(self::PROPERTY, $this->emails([$message->id], null)[(string) $message->id]);
        self::assertArrayNotHasKey(self::PROPERTY, $this->emails([$message->id], ['id', 'subject'])[(string) $message->id]);
    }

    public function testEveryChangeIsAnUpdateInEmailChanges(): void
    {
        $message = $this->receivedMessage();
        $id      = (string) $message->id;

        foreach ([
            'assign'   => fn () => $this->assigner->assign($message, $this->user, 'BS-0000-0001'),
            'move'     => fn () => $this->assigner->assign($message, $this->user, 'BS-0000-0002'),
            'none'     => fn () => $this->assigner->markNone($message, $this->user),
            'clear'    => fn () => $this->assigner->clear($message),
        ] as $what => $change) {
            $since = $this->emailState();
            $change();

            self::assertNotSame($since, $this->emailState(), $what . ': the state moves');

            $changes = self::getContainer()->get(EmailChangesMethod::class)->handle(
                ['accountId' => $this->accountId(), 'sinceState' => $since],
                $this->context(),
            );

            self::assertSame([$id], $changes['updated'], $what);
            self::assertSame([], $changes['created'], $what);
        }

        self::assertNull($this->emails([$message->id], [self::PROPERTY])[$id][self::PROPERTY], 'cleared is null again');
    }

    /** The user row may go; that a person decided, and who, stays. */
    public function testWhoAssignedItOutlivesTheirUserRow(): void
    {
        $message = $this->receivedMessage();

        $colleague            = new User();
        $colleague->email     = 'colleague-' . uniqid('', true) . '@example.test';
        $colleague->nameFirst = 'Anna';
        $colleague->nameLast  = 'Muster';
        $colleague->roles     = ['ROLE_USER'];
        $colleague->password  = 'x';
        $this->em->persist($colleague);

        $row = new ForkMessageProject($message);
        $row->assign('BS-0000-0001', 'Rathaus Umbau', $colleague);
        $this->em->persist($row);
        $this->em->flush();

        $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$colleague->id]);
        $this->em->clear();

        $value = $this->emails([$message->id], [self::PROPERTY])[(string) $message->id][self::PROPERTY];

        self::assertSame('Anna Muster', $value['assignedBy']);
        self::assertNull($this->connection->fetchOne('SELECT assigned_by_id FROM fork_message_project WHERE message_id = ?', [$message->id]));
    }

    /**
     * @param list<int|null>    $ids
     * @param list<string>|null $properties
     *
     * @return array<string, array<string, mixed>> by id
     */
    private function emails(array $ids, ?array $properties): array
    {
        $result = $this->get->handle([
            'accountId'  => $this->accountId(),
            'ids'        => array_map('strval', $ids),
            'properties' => $properties,
        ], $this->context());

        return array_column($result['list'], null, 'id');
    }

    private function emailState(): string
    {
        return self::getContainer()->get(StateManager::class)->stateFor((int) $this->account->id, JmapObjectType::Email);
    }
}
