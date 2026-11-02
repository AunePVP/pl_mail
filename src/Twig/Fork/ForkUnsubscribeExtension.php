<?php

declare(strict_types=1);

namespace App\Twig\Fork;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Message;
use App\Service\Mail\HeaderNormalizer;
use App\Service\Mail\MailPlacement;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FORK (FORK.md): `fork_unsubscribe(message)` — where the message header's
 * Unsubscribe button points, read from List-Unsubscribe (RFC 2369).
 *
 * The header lists one or more targets in angle brackets, in the sender's
 * order of preference: `<https://…>, <mailto:…>`. A web link is preferred over
 * a mailto: whatever the order, because it opens a page the reader confirms on
 * rather than a draft they have to send. Only http(s) and mailto: are taken —
 * the value is a URL a stranger wrote, and it becomes an href.
 *
 * Two shapes seen in real mail that the RFC does not describe: the whole value
 * as MIME encoded-words (folded by the sender's mailer and stored undecoded),
 * and a bare URL without the brackets. Both are read.
 *
 * Nothing for mail in spam or the bin: an unsubscribe link in spam mostly tells
 * the spammer the address is read, which is why Gmail hides it there too.
 */
final class ForkUnsubscribeExtension extends AbstractExtension
{
    public function __construct(
        private readonly HeaderNormalizer $headers,
        private readonly MailPlacement $placement,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fork_unsubscribe', $this->target(...)),
        ];
    }

    /**
     * @return array{url: string}|array{to: string}|null a web link, a mailbox to write to, or nothing
     */
    public function target(Message $message): ?array
    {
        if (true === in_array($this->placement->of($message), [MailPlacement::SPAM, MailPlacement::TRASH], true)) {
            return null;
        }

        return self::parse($this->headers->first($message->headers, 'list-unsubscribe'));
    }

    /**
     * @return array{url: string}|array{to: string}|null
     */
    public static function parse(?string $value): ?array
    {
        if (null === $value) {
            return null;
        }

        // A real encoded-word, not just "=?": Marketo links carry that inside
        // the URL, and decoding them as MIME mangles them.
        if (1 === preg_match('/=\?[^?\s]+\?[QB]\?[^?\s]*\?=/i', $value)) {
            $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            $value   = false === $decoded ? $value : $decoded;
        }

        $value = trim($value);

        preg_match_all('/<([^>]*)>/', $value, $matches);
        $candidates = $matches[1];

        // Without brackets the folding whitespace is still in the URL.
        if ([] === $candidates && 1 === preg_match('~^https?://~i', $value)) {
            $candidates = [$value];
        }

        $mailto = null;

        foreach ($candidates as $candidate) {
            // Folding can leave whitespace inside the brackets.
            $candidate = preg_replace('/\s+/', '', $candidate) ?? '';

            if (1 === preg_match('~^https?://[^/?#\s]+~i', $candidate)) {
                return ['url' => $candidate];
            }

            if (null === $mailto && 1 === preg_match('/^mailto:([^?]+)/i', $candidate, $address)) {
                $email = AddressHelper::email(rawurldecode($address[1]));

                if (true === AddressHelper::isValidEmail($email)) {
                    $mailto = ['to' => $email];
                }
            }
        }

        return $mailto;
    }
}
