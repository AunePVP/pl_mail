<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail\Fork;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageThreadRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FORK (FORK.md 23): the "Unread first" list order.
 *
 * Unread conversations on top, newest first, then the read ones, newest first —
 * through the DISTINCT inbox query and the plain all-mail query, the two shapes
 * every folder list is built from.
 */
final class UnreadFirstSortTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->thread('Read, newest', lastMessageAt: '-1 hour');
        $this->thread('Unread, old', lastMessageAt: '-3 days', unread: 1);
        $this->thread('Read, old', lastMessageAt: '-2 days');
        $this->thread('Unread, newer', lastMessageAt: '-2 hours', unread: 2);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheInboxListsUnreadFirstThenByDate(): void
    {
        $rows = $this->repository()->findForUnifiedInbox(
            $this->user,
            MessageCategory::Primary,
            sort: ListSortOrder::UnreadFirst,
        );

        self::assertSame(
            ['Unread, newer', 'Unread, old', 'Read, newest', 'Read, old'],
            $this->subjects($rows),
        );
    }

    public function testAllMailListsUnreadFirstThenByDate(): void
    {
        $rows = $this->repository()->findForUnifiedAllMail($this->user, sort: ListSortOrder::UnreadFirst);

        self::assertSame(
            ['Unread, newer', 'Unread, old', 'Read, newest', 'Read, old'],
            $this->subjects($rows),
        );
    }

    /** The order does not narrow: a page holds exactly what the count promised. */
    public function testPagingKeepsTheOrderAcrossPages(): void
    {
        $repository = $this->repository();

        $first  = $repository->findForUnifiedInbox($this->user, MessageCategory::Primary, 1, 2, ListSortOrder::UnreadFirst);
        $second = $repository->findForUnifiedInbox($this->user, MessageCategory::Primary, 2, 2, ListSortOrder::UnreadFirst);

        self::assertSame(['Unread, newer', 'Unread, old'], $this->subjects($first));
        self::assertSame(['Read, newest', 'Read, old'], $this->subjects($second));
    }

    private function repository(): MessageThreadRepository
    {
        return static::getContainer()->get(MessageThreadRepository::class);
    }

    /**
     * @param array<MessageThread> $rows
     *
     * @return list<string|null>
     */
    private function subjects(array $rows): array
    {
        return array_values(array_map(static fn (MessageThread $t): ?string => $t->subject, $rows));
    }
}
