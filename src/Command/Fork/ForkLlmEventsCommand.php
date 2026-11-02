<?php

declare(strict_types=1);

namespace App\Command\Fork;

use App\Infrastructure\Messaging\Handler\ExtractEventsHandler;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FORK (FORK.md): run event extraction, the model included, over mail that is
 * already here.
 *
 * `app:backfill events` only visits mail with markup or a calendar part, which
 * is exactly the mail ForkLlmTravelExtractor never reads. New mail goes through
 * it on arrival; this is for the bookings that arrived before it existed.
 *
 * Through ExtractEventsHandler, inline, so a message is extracted here exactly
 * as it would have been on arrival: Spam and the bin skipped, flushed per
 * message, the calendar told once at the end. Re-running it is harmless —
 * the reconciler recognises what it has already seen and the model's answers
 * are cached per message.
 *
 * Only mail whose subject mentions travel is visited, the same words the
 * extractor's own gate starts from, so a mailbox of fifty thousand messages is
 * not walked message by message to ask a regex about each.
 */
#[AsCommand(
    name: 'app:fork:llm-events',
    description: 'Read travel bookings out of existing mail without markup (asks the chat model)',
)]
final class ForkLlmEventsCommand extends Command
{
    private const int BATCH = 20;

    private const string SUBJECT = '(flug|flight|boarding|bordkarte|abflug|buchung|booking|reservierung|reservation|hotel|bahn|zug|train|ticket|fahrkarte|mietwagen|itinerary|reise|trip|check-?in)';

    public function __construct(
        private readonly Connection             $connection,
        private readonly ExtractEventsHandler   $handler,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Mail received on or after this date', '-60 days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the messages that would be read');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $since = new DateTimeImmutable((string) $input->getOption('since'));
        } catch (\Throwable) {
            $io->error('--since is not a date.');

            return Command::INVALID;
        }

        /** @var list<int> $ids */
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT m.id FROM message m
              WHERE m.received_at >= :since
                AND (m.category IS NULL OR m.category NOT IN ('promotions', 'forums'))
                AND m.subject ~* :subject
              ORDER BY m.id",
            ['since' => $since->format('Y-m-d H:i:s'), 'subject' => self::SUBJECT],
        ));

        $io->text(sprintf('%d messages since %s.', count($ids), $since->format('Y-m-d')));

        if (true === $input->getOption('dry-run') || [] === $ids) {
            return Command::SUCCESS;
        }

        $io->progressStart(count($ids));

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            ($this->handler)(new ExtractEventsMessage($batch));
            $this->em->clear();
            $io->progressAdvance(count($batch));
        }

        $io->progressFinish();

        return Command::SUCCESS;
    }
}
