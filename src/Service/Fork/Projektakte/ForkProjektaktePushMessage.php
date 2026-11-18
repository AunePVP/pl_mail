<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * FORK (FORK.md 30): "the Projektakte outbox has something due". Carries
 * nothing: the outbox is the queue, this only wakes a courier.
 *
 * On `maintenance` by its own attribute rather than a line in
 * messenger.yaml: not `export`, which is mail somebody is waiting to see sent,
 * and a 200 MB upload must not stand in front of that.
 */
#[AsMessage('maintenance')]
final readonly class ForkProjektaktePushMessage
{
}
