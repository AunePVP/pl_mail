<?php

declare(strict_types=1);

namespace App\Domain\Enum\Fork;

/**
 * FORK (FORK.md 30): what a row of the Projektakte outbox asks for.
 *
 * Only a hint: the courier sends what is true when it gets to the row, so a
 * Send for a mail whose assignment has since been taken off goes out as a
 * Remove, and a Remove for a mail that is filed again goes out as a Send.
 * What the hint decides is how much to send for a mail that is filed:
 *
 *   Send      the whole .eml with the assignment (PUT …/mails/{a}/{e})
 *   Reassign  only the new assignment, for a mail Projektakte already has
 *             (PUT …/zuordnung); a 404 there falls back to Send
 *   Remove    DELETE …/mails/{a}/{e}
 */
enum ForkPushAction: string
{
    case Send     = 'send';
    case Reassign = 'reassign';
    case Remove   = 'remove';
}
