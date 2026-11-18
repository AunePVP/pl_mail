<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Entity\Mail\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;

/**
 * FORK (FORK.md 30): the header lines a mail sent to Projektakte must carry —
 * Subject, From, To, Cc, Date, Message-ID — and that its source lacks, made
 * from what plMail knows about the message.
 *
 * Projektakte reads subject, sender and date from these headers; a mail
 * without them is close to unfindable there. The stored source normally has
 * them all and gets nothing; the reconstruction MessageSourceBuilder falls
 * back to has only what the message row kept of the headers, which can be
 * none.
 *
 * Only added, never replaced: a header the source has stays as it is, so
 * the bytes Projektakte stores are the original's wherever there are any.
 * The missing ones go in front, which a mail parser reads like any other
 * header line. To and Cc are added only when the message has such
 * recipients (an empty address list is not a valid header); Subject is
 * added even when empty, so the field is there. A Message-ID the message
 * never had is made up from its JMAP ids, so the same mail always gets the
 * same one.
 */
final class ForkEmlHeaders
{
    /** How far into a source to look for the end of its header block. */
    public const int HEAD_BYTES = 1024 * 1024;

    private const array REQUIRED = ['subject', 'from', 'to', 'cc', 'date', 'message-id'];

    /**
     * What to put in front of the source: rendered header lines, with the
     * source's own line ending, or '' when nothing is missing.
     *
     * @param string $head the beginning of the source (up to HEAD_BYTES)
     */
    public static function missing(string $head, Message $message, int $accountId, int $emailId): string
    {
        $block   = self::headerBlock($head);
        $present = self::names($block);
        $missing = array_values(array_diff(self::REQUIRED, $present));

        if ([] === $missing) {
            return '';
        }

        $headers = new Headers();

        foreach ($missing as $name) {
            self::add($headers, $name, $message, $accountId, $emailId);
        }

        $rendered = $headers->toString();

        if ('' === $rendered) {
            return '';
        }

        // Symfony renders CRLF; a source written with bare LF keeps bare LF.
        $sample = '' !== $block ? $block : $head;
        $eol    = true === str_contains($sample, "\r\n") || false === str_contains($sample, "\n") ? "\r\n" : "\n";

        return "\r\n" === $eol ? $rendered : str_replace("\r\n", "\n", $rendered);
    }

    /** The header block of a source: everything before the first empty line. */
    public static function headerBlock(string $head): string
    {
        $ends = array_filter([strpos($head, "\r\n\r\n"), strpos($head, "\n\n")], static fn (int|false $at): bool => false !== $at);

        return [] === $ends ? $head : substr($head, 0, min($ends));
    }

    /** @return list<string> the header names in a block, lower case */
    public static function names(string $block): array
    {
        preg_match_all('/^([!-9;-~]+)[ \t]*:/m', $block, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    private static function add(Headers $headers, string $name, Message $message, int $accountId, int $emailId): void
    {
        switch ($name) {
            case 'subject':
                $headers->addTextHeader('Subject', (string) $message->subject);

                return;

            case 'from':
                $from = self::addresses([['address' => $message->fromAddress, 'name' => $message->fromName]]);

                if ([] !== $from) {
                    $headers->addMailboxListHeader('From', $from);
                }

                return;

            case 'to':
            case 'cc':
                $list = self::addresses('to' === $name ? $message->toAddresses : $message->ccAddresses);

                if ([] !== $list) {
                    $headers->addMailboxListHeader('to' === $name ? 'To' : 'Cc', $list);
                }

                return;

            case 'date':
                $headers->addDateHeader('Date', $message->sentAt ?? $message->receivedAt ?? new \DateTimeImmutable());

                return;

            case 'message-id':
                $id = trim((string) $message->messageId, " \t<>");
                $id = '' !== $id ? $id : sprintf('plmail.%d.%d@projektakte.invalid', $accountId, $emailId);

                try {
                    $headers->addIdHeader('Message-ID', $id);
                } catch (\Throwable) {
                    // Not addr-spec shaped (some senders write anything);
                    // as it came, rather than not at all.
                    $headers->addTextHeader('Message-ID', '<' . $id . '>');
                }

                return;
        }
    }

    /**
     * @param array<mixed>|null $entries as stored on Message: {address|email, name}
     *
     * @return list<Address>
     */
    private static function addresses(?array $entries): array
    {
        $list = [];

        foreach ($entries ?? [] as $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            $email = $entry['address'] ?? $entry['email'] ?? null;
            $name  = $entry['name'] ?? '';

            if (false === is_string($email) || '' === trim($email)) {
                continue;
            }

            try {
                $list[] = new Address(trim($email), true === is_string($name) ? $name : '');
            } catch (\Throwable) {
                // An address Symfony will not render (malformed at the
                // sender); the others still go.
            }
        }

        return $list;
    }
}
