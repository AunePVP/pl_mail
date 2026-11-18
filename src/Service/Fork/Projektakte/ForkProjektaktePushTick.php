<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * FORK (FORK.md 30): once a minute, wake a courier if the Projektakte outbox
 * has something due — retries whose time has come, the rest of a long resend,
 * and anything whose nudge was lost to a restart.
 *
 * Joins the `default` schedule (MaintenanceSchedule) by attribute, so the
 * original's schedule file needs no line. It runs inside the scheduler
 * worker, which the other schedule entries share, so it only looks and
 * dispatches; the delivering happens on `maintenance`.
 */
#[AsCronTask('* * * * *', method: 'tick')]
final readonly class ForkProjektaktePushTick
{
    public function __construct(
        private ForkProjektakteOutboxStore $outbox,
        private ForkProjektakteClient      $projektakte,
        private MessageBusInterface        $bus,
    ) {
    }

    public function tick(): void
    {
        if (true === $this->projektakte->isAvailable() && true === $this->outbox->hasDue()) {
            $this->bus->dispatch(new ForkProjektaktePushMessage());
        }
    }
}
