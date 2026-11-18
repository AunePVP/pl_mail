<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Domain\Enum\Fork\ForkProjectStatus;
use App\Domain\Enum\Fork\ForkPushAction;
use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Service\Mail\MessageSourceBuilder;
use App\Service\Mail\RawMessageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * FORK (FORK.md 30): takes the Projektakte outbox to Projektakte, in the
 * background (ForkProjektaktePushHandler, and the minute tick behind it).
 *
 * Each row is a mail, and what goes out is what is true when the courier gets
 * to it, not what was true when it was queued: the assignment is read now,
 * so a mail filed and taken back before anything went out leaves as one
 * DELETE, and the queue cannot send a state that no longer exists. The row's
 * action only says how much a filed mail needs (ForkPushAction).
 *
 * Nothing but a filed mail's own .eml and its assignment ever leaves, and only
 * to the address the installation configured. No list, no preview.
 *
 * Failures, by the contract: no answer or 5xx (and a refused token, which
 * someone can fix) are tried again, 1, 2, 4 … minutes later up to an hour
 * apart, for as long as it takes; any other refusal (400, 413, 422) will not
 * change by asking again, so it is logged, kept as the last error, and the
 * row is dropped.
 */
final readonly class ForkProjektakteCourier
{
    /** What Projektakte takes, attachments included. */
    public const int MAX_BYTES = 200 * 1024 * 1024;

    private const int FIRST_DELAY = 60;

    private const int MAX_DELAY = 3600;

    private const string LAST_ERROR_KEY = 'fork_projektakte_push_last_error';

    public function __construct(
        private ForkProjektakteOutboxStore   $outbox,
        private ForkProjektakteClient        $projektakte,
        private ForkMessageProjectRepository $assignments,
        private EntityManagerInterface       $em,
        private RawMessageResolver           $raw,
        private MessageSourceBuilder         $source,
        private CacheItemPoolInterface       $cache,
        private LoggerInterface              $logger,
    ) {
    }

    /**
     * Deliver what is due, one mail at a time, until nothing is or the time is
     * up (the rest is the next run's).
     *
     * @return int how many rows were handled, delivered or not
     */
    public function deliverDue(float $seconds = 240.0): int
    {
        if (false === $this->projektakte->isAvailable()) {
            return 0;
        }

        $until   = microtime(true) + $seconds;
        $handled = 0;

        while (microtime(true) < $until && null !== ($row = $this->outbox->claim())) {
            $this->deliver($row);
            ++$handled;

            // The rows are independent; what was loaded for one is not needed
            // for the next, and a resend of a thousand mails must not keep a
            // thousand messages and their parts in memory.
            $this->em->clear();
        }

        return $handled;
    }

    /**
     * The last refusal or failure, kept until the next one — the outbox row
     * that had it may be gone (dropped, or delivered on a later try).
     *
     * @return array{at: string, error: string, emailId: int}|null
     */
    public function lastError(): ?array
    {
        $item = $this->cache->getItem(self::LAST_ERROR_KEY);

        return true === $item->isHit() && true === is_array($item->get()) ? $item->get() : null;
    }

    /** @param array{id: int, accountId: int, emailId: int, action: ForkPushAction, version: int, attempts: int} $row */
    private function deliver(array $row): void
    {
        try {
            $answer = $this->send($row['accountId'], $row['emailId'], $row['action']);
        } catch (\Throwable $e) {
            // Something of ours (the raw file, the database), not Projektakte:
            // try again later like an outage rather than lose the row.
            $answer = new ForkPushAnswer(0, $e->getMessage());
        }

        if (true === $answer->taken()) {
            $this->outbox->finish($row['id'], $row['version']);

            return;
        }

        $this->remember($row['emailId'], $answer);

        if (true === $answer->worthRetrying()) {
            $this->outbox->retry($row['id'], $row['version'], self::nextTry($row['attempts']), $answer->describe());

            return;
        }

        $this->logger->error('Projektakte: a push was refused and is dropped', [
            'account' => $row['accountId'],
            'email'   => $row['emailId'],
            'action'  => $row['action']->value,
            'error'   => $answer->describe(),
        ]);
        $this->outbox->finish($row['id'], $row['version']);
    }

    private function send(int $accountId, int $emailId, ForkPushAction $action): ForkPushAnswer
    {
        $message    = $this->em->find(Message::class, $emailId);
        $assignment = null !== $message ? $this->assignments->forMessage($message) : null;

        // Not filed (any more), or gone: Projektakte must not have it.
        if (null === $message || null === $assignment || ForkProjectStatus::Assigned !== $assignment->status) {
            return $this->projektakte->withdrawMail($accountId, $emailId);
        }

        if (ForkPushAction::Reassign === $action) {
            $answer = $this->projektakte->pushAssignment($accountId, $emailId, $assignment->toJmap());

            if (404 !== $answer->status) {
                return $answer;
            }
        }

        return $this->sendMail($message, $accountId, $emailId, $assignment);
    }

    private function sendMail(Message $message, int $accountId, int $emailId, ForkMessageProject $assignment): ForkPushAnswer
    {
        // The genuine bytes when plMail has (or can fetch) them; else the
        // reconstruction the JMAP blob download falls back to (BlobResolver).
        $path = $this->raw->absolutePathFor($message);

        if (null !== $path) {
            $size   = (int) filesize($path);
            $stream = fopen($path, 'r');
        } else {
            $eml    = $this->source->build($message);
            $size   = strlen($eml);
            $stream = fopen('php://temp', 'r+');

            if (false !== $stream) {
                fwrite($stream, $eml);
                rewind($stream);
            }
        }

        if (false === $stream) {
            return new ForkPushAnswer(0, 'the mail could not be read');
        }

        try {
            // Subject, From, To, Cc, Date and Message-ID, from plMail's own
            // fields where the source has none of its own (ForkEmlHeaders).
            $prefix = ForkEmlHeaders::missing((string) stream_get_contents($stream, ForkEmlHeaders::HEAD_BYTES, 0), $message, $accountId, $emailId);
            rewind($stream);
            $size += strlen($prefix);

            if (self::MAX_BYTES < $size) {
                // Projektakte would answer 413; say it without sending 200 MB first.
                return new ForkPushAnswer(413, sprintf('the mail is %d MB, more than Projektakte takes', intdiv($size, 1024 * 1024)));
            }

            $body = '' === $prefix ? $stream : self::prepend($prefix, $stream);

            return $this->projektakte->pushMail($accountId, $emailId, $body, $size, $assignment->toJmap());
        } finally {
            if (true === is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The added header lines, then the source, streamed: a 200 MB mail is
     * never held in memory to put a few lines in front of it.
     *
     * @param resource $stream
     *
     * @return \Closure(int): string
     */
    private static function prepend(string $prefix, mixed $stream): \Closure
    {
        return static function (int $length) use (&$prefix, $stream): string {
            if ('' !== $prefix) {
                $chunk  = substr($prefix, 0, max(1, $length));
                $prefix = (string) substr($prefix, strlen($chunk));

                return $chunk;
            }

            $chunk = fread($stream, max(1, $length));

            return false === $chunk ? '' : $chunk;
        };
    }

    private function remember(int $emailId, ForkPushAnswer $answer): void
    {
        $item = $this->cache->getItem(self::LAST_ERROR_KEY);
        $item->set(['at' => new \DateTimeImmutable()->format(\DATE_ATOM), 'error' => $answer->describe(), 'emailId' => $emailId]);
        $item->expiresAfter(30 * 86400);
        $this->cache->save($item);
    }

    /** 1, 2, 4 … minutes after the first, the second … failure, at most an hour. */
    private static function nextTry(int $attempts): \DateTimeImmutable
    {
        $delay = min(self::MAX_DELAY, self::FIRST_DELAY * (2 ** min($attempts, 10)));

        return new \DateTimeImmutable(sprintf('+%d seconds', $delay));
    }
}
