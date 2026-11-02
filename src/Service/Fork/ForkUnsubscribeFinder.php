<?php

declare(strict_types=1);

namespace App\Service\Fork;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * FORK (FORK.md): the unsubscribe link in a mail's own text, for mail whose
 * sender sends no List-Unsubscribe header — the footer's "click here to
 * unsubscribe".
 *
 * Two kinds of match, the first winning over the second:
 *
 *   - a link whose own text says it: "Unsubscribe", "Newsletter abbestellen";
 *   - the one link in a short sentence that says it: "Wenn du keine weiteren
 *     Nachrichten erhalten möchtest, kannst du dich [hier] abmelden." The
 *     sentence is the link's nearest enclosing element whose text mentions
 *     unsubscribing, and it has to be short and hold no other web link — a
 *     footer row "Abmelden | Einstellungen | Impressum" says nothing about
 *     which of its three links is which.
 *
 * Of several matches the last one in the mail is taken: the footer is at the
 * bottom. Links inside a quote are skipped, so a reply that quotes a
 * newsletter does not borrow its link.
 *
 * Plain-text mail is searched line by line: a line that says it, and the one
 * URL on it or on the line after.
 */
final class ForkUnsubscribeFinder
{
    /**
     * Stems rather than words: German builds "abzumelden" and
     * "Newsletter-Abmeldung", French "désinscrire" and "désinscription".
     */
    private const string WORDS = '/unsubscri|opt[\s-]?out|abmeld|abzumeld|abbestell|abzubestell|austragen|auszutragen'
        . '|d[ée]sabonn|d[ée]sinscri|afmeld|uitschrijv|darse de baja|darte de baja|cancelar (?:la |tu )?suscripci'
        . '|disiscriv|annulla(?:re)? l.iscrizione|退订|取消订阅/iu';

    /**
     * What the one link of an unsubscribe sentence is called when it IS the
     * unsubscribe link: "kannst du dich [hier] abmelden". A sentence can say
     * "unsubscribe" and link something else entirely — "To unsubscribe, see
     * our [Help Centre]", a privacy line with [Datenschutzerklärung] — so the
     * sentence alone is not enough.
     */
    private const string POINTER = '/^(?:bitte\s+)?(?:hier|here|link|ici|aqu[ií]|qui|this link|diesen link|dem link|ce lien'
        . '|(?:click|klicken sie|klicke(?: bitte)?|cliquez|clicca) (?:here|hier|ici|qui)|hier klicken|here to \w+)$/iu';

    /** …or it, or where it goes, says so: an unsubscribe or preferences page. */
    private const string POINTED = '/unsub|abmeld|abbestell|opt-?out|preferences|pr(?:ae|ä)ferenz|einstellungen|settings|subscription|abonnement/iu';

    /** Linked from privacy footers as "opt-out", and never the mail's own unsubscribe. */
    private const string NEVER = '~^https?://tools\.google\.com/dlpage/gaoptout~i';

    /** Longer than this, an element is a section of the mail rather than a sentence about a link. */
    private const int SENTENCE = 400;

    private const string WEB = '~^https?://[^/?#\s]+~i';

    public static function find(?string $html, ?string $text): ?string
    {
        if (null !== $html && '' !== trim($html)) {
            return self::inHtml($html);
        }

        if (null !== $text && '' !== trim($text)) {
            return self::inText($text);
        }

        return null;
    }

    private static function inHtml(string $html): ?string
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return null;
        }

        $named    = null;
        $sentence = null;

        foreach ($document->querySelectorAll('a[href]') as $link) {
            $href = trim((string) $link->getAttribute('href'));

            if (1 !== preg_match(self::WEB, $href) || 1 === preg_match(self::NEVER, $href) || self::quoted($link)) {
                continue;
            }

            if (1 === preg_match(self::WORDS, self::words($link))) {
                $named = $href;

                continue;
            }

            if (null === $named && self::pointsHere($link, $href) && self::onlyLinkOfASentenceThatSaysIt($link, $href)) {
                $sentence = $href;
            }
        }

        return $named ?? $sentence;
    }

    /** The link's text, and the alt text of an image standing in for it. */
    private static function words(Element $link): string
    {
        $words = (string) $link->textContent;

        foreach ($link->querySelectorAll('img[alt]') as $image) {
            $words .= ' ' . $image->getAttribute('alt');
        }

        return $words . ' ' . $link->getAttribute('title');
    }

    private static function pointsHere(Element $link, string $href): bool
    {
        $words = trim((string) preg_replace('/\s+/u', ' ', (string) $link->textContent), " \t\n.,:;!»«\"'„“");

        return 1 === preg_match(self::POINTER, $words)
            || 1 === preg_match(self::POINTED, $href)
            || 1 === preg_match(self::POINTED, $words);
    }

    private static function onlyLinkOfASentenceThatSaysIt(Element $link, string $href): bool
    {
        for ($node = $link->parentElement; null !== $node; $node = $node->parentElement) {
            $words = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));

            if (mb_strlen($words) > self::SENTENCE) {
                return false;
            }

            if (1 !== preg_match(self::WORDS, $words)) {
                continue;
            }

            foreach ($node->querySelectorAll('a[href]') as $other) {
                $otherHref = trim((string) $other->getAttribute('href'));

                if ($otherHref !== $href && 1 === preg_match(self::WEB, $otherHref)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** Inside a quoted message: <blockquote>, or the classes mailers mark a quote with. */
    private static function quoted(Element $link): bool
    {
        for ($node = $link->parentElement; null !== $node; $node = $node->parentElement) {
            if ('BLOCKQUOTE' === $node->tagName) {
                return true;
            }

            $marks = $node->getAttribute('class') . ' ' . $node->getAttribute('id');

            if (1 === preg_match('/gmail_quote|moz-cite|divRplyFwdMsg|yahoo_quoted|protonmail_quote/i', $marks)) {
                return true;
            }
        }

        return false;
    }

    private static function inText(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $found = null;

        foreach ($lines as $index => $line) {
            if (str_starts_with(ltrim($line), '>') || 1 !== preg_match(self::WORDS, $line)) {
                continue;
            }

            foreach ([$line, $lines[$index + 1] ?? ''] as $candidate) {
                preg_match_all('~https?://[^\s<>"\]\)]+~i', $candidate, $urls);
                $urls = array_values(array_unique($urls[0]));

                if (1 === count($urls)) {
                    $found = $urls[0];

                    break;
                }
            }
        }

        return $found;
    }
}
