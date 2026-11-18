<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Domain\Enum\Fork\ForkPushAction;
use App\Entity\Mail\Message;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * FORK (FORK.md 30): what plMail sends to Projektakte goes in here, and only
 * here: a mail a person filed under a project, filed elsewhere, taken back,
 * or erased.
 *
 * Queuing writes the outbox row (durable: it survives a restart and an
 * outage) and asks the background to deliver now (ForkProjektaktePushMessage);
 * nothing here waits for Projektakte, so a click never does. Should that
 * nudge be lost, the minute tick (ForkProjektaktePushTick) finds the row.
 *
 * Without PROJEKTAKTE_URL and PROJEKTAKTE_TOKEN nothing is queued: there is
 * nowhere to send it, and "Send all filed mail" covers whatever was filed
 * before the address was set.
 */
final readonly class ForkProjektaktePush
{
    private const string RESEND_KEY = 'fork_projektakte_push_resend';

    public function __construct(
        private ForkProjektakteOutboxStore $outbox,
        private ForkProjektakteClient      $projektakte,
        private MessageBusInterface        $bus,
        private CacheItemPoolInterface     $cache,
    ) {
    }

    public function queue(Message $message, ForkPushAction $action): void
    {
        if (null === $message->id || null === $message->account->id) {
            return;
        }

        $this->queueIds([[(int) $message->account->id, (int) $message->id]], $action);
    }

    /** @param list<array{int, int}> $mails account id and email id each */
    public function queueIds(array $mails, ForkPushAction $action): void
    {
        if ([] === $mails || false === $this->projektakte->isAvailable()) {
            return;
        }

        foreach ($mails as [$accountId, $emailId]) {
            $this->outbox->put($accountId, $emailId, $action);
        }

        $this->nudge();
    }

    /**
     * Every filed mail, once each, as a Send: for the start, and after an
     * outage on either side. Remembers how many, for the progress shown.
     */
    public function resendAll(): int
    {
        if (false === $this->projektakte->isAvailable()) {
            return 0;
        }

        $queued = $this->outbox->putAllAssigned();

        $item = $this->cache->getItem(self::RESEND_KEY);
        $item->set(['total' => $queued, 'at' => new \DateTimeImmutable()->format(\DATE_ATOM)]);
        $item->expiresAfter(7 * 86400);
        $this->cache->save($item);

        $this->nudge();

        return $queued;
    }

    /**
     * Before an account's mail goes wholesale (the account deleted, a data
     * reset): a DELETE for each of its filed mails, or for every filed mail
     * when $accountId is null. The outbox has no foreign key to the account,
     * so the rows outlive it until delivered.
     */
    public function queueRemovalOfFiled(?int $accountId = null): int
    {
        if (false === $this->projektakte->isAvailable()) {
            return 0;
        }

        $queued = $this->outbox->putRemovalOfFiled($accountId);

        if (0 < $queued) {
            $this->nudge();
        }

        return $queued;
    }

    /**
     * The last "send all": how many it queued and when.
     *
     * @return array{total: int, at: string}|null
     */
    public function lastResend(): ?array
    {
        $item = $this->cache->getItem(self::RESEND_KEY);

        return true === $item->isHit() && true === is_array($item->get()) ? $item->get() : null;
    }

    public function waiting(): int
    {
        return $this->outbox->count();
    }

    private function nudge(): void
    {
        $this->bus->dispatch(new ForkProjektaktePushMessage());
    }
}
