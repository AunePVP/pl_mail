<?php

declare(strict_types=1);

namespace App\Tests\Controller\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FORK (FORK.md 28): a folder list opens unread-first until another order is
 * chosen, and a chosen order still holds for the rest of the session.
 */
final class ForkUnreadFirstDefaultTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    /** @var array<string, int> subject => thread id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        foreach ([
            ['Read, newest', '-1 hour', 0],
            ['Unread, old', '-3 days', 1],
            ['Read, old', '-2 days', 0],
        ] as [$subject, $at, $unread]) {
            $this->ids[$subject] = (int) $this->thread($subject, lastMessageAt: $at, unread: $unread)->id;
        }

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAListOpensUnreadFirstAndAChosenOrderSticks(): void
    {
        self::assertSame(['Unread, old', 'Read, newest', 'Read, old'], $this->listed('/mail/all'));
        self::assertSelectorExists('[data-search-sort="unread"][aria-current="true"]');

        self::assertSame(['Read, newest', 'Read, old', 'Unread, old'], $this->listed('/mail/all?sort=newest'));
        self::assertSame(['Read, newest', 'Read, old', 'Unread, old'], $this->listed('/mail/all'), 'remembered for the session');
    }

    /** @return list<string> the seeded subjects in the order the list shows them */
    private function listed(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $order = [];
        foreach ($crawler->filter('[id^="thread_"]')->extract(['id']) as $id) {
            $subject = array_search((int) substr((string) $id, 7), $this->ids, true);
            if (false !== $subject && !in_array($subject, $order, true)) {
                $order[] = $subject;
            }
        }

        return $order;
    }
}
