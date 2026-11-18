<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * FORK (FORK.md 30): delivers the Projektakte outbox, a few minutes' worth at
 * a time. What is left (a long resend) is picked up by the minute tick, so one
 * job never holds the maintenance worker for an hour.
 *
 * Never throws for Projektakte's sake: a failed delivery is the outbox row's
 * to retry, not Messenger's.
 */
#[AsMessageHandler]
final readonly class ForkProjektaktePushHandler
{
    public function __construct(
        private ForkProjektakteCourier $courier,
    ) {
    }

    public function __invoke(ForkProjektaktePushMessage $message): void
    {
        $this->courier->deliverDue();
    }
}
