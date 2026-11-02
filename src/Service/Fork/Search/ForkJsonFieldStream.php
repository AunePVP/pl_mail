<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

/**
 * FORK (FORK.md): one string field of a JSON object, read while the object is
 * still being written.
 *
 * The search answer is one JSON object — the prose, the mail worth opening,
 * the groups — and the model writes it a few characters at a time. Only the
 * prose is worth watching arrive, and only once it is decoded: a reader shown
 * `Dein Flug über` would be reading the model's escaping, not its answer.
 *
 * So this is fed the raw tokens and hands back the new text of the one field
 * each time: nothing before its key, nothing after its closing quote, every
 * escape decoded, and an escape or a UTF-8 character that a token cut in half
 * held back until the rest of it arrives.
 */
final class ForkJsonFieldStream
{
    private string $buffer = '';

    /** Where the field's value starts in $buffer, once its key has been seen. */
    private ?int $cursor = null;

    private bool $closed = false;

    /** Bytes of the value already decoded but not yet handed out (a cut-off character). */
    private string $pending = '';

    public function __construct(private readonly string $field)
    {
    }

    /** The field's text that this chunk completed; '' while there is none. */
    public function feed(string $chunk): string
    {
        if (true === $this->closed) {
            return '';
        }

        $this->buffer .= $chunk;

        if (null === $this->cursor) {
            $pattern = sprintf('/"%s"\s*:\s*"/', preg_quote($this->field, '/'));

            if (1 !== preg_match($pattern, $this->buffer, $match, \PREG_OFFSET_CAPTURE)) {
                return '';
            }

            $this->cursor = $match[0][1] + strlen($match[0][0]);
        }

        $out    = $this->pending;
        $length = strlen($this->buffer);

        while ($this->cursor < $length) {
            $char = $this->buffer[$this->cursor];

            if ('"' === $char) {
                $this->closed = true;

                break;
            }

            if ('\\' !== $char) {
                $out .= $char;
                ++$this->cursor;

                continue;
            }

            $escape = $this->escapeAt($this->cursor);

            if (null === $escape) {
                break;
            }

            $out          .= $escape[0];
            $this->cursor += $escape[1];
        }

        $this->pending = '';

        // A multibyte character the token boundary cut: hold its first bytes back.
        if (false === $this->closed) {
            for ($keep = 0; $keep <= 3 && $keep <= strlen($out); ++$keep) {
                $head = substr($out, 0, strlen($out) - $keep);

                if (true === mb_check_encoding($head, 'UTF-8')) {
                    $this->pending = substr($out, strlen($head));

                    return $head;
                }
            }
        }

        return mb_scrub($out, 'UTF-8');
    }

    /**
     * The decoded escape at $at and how many bytes it took, or null when the
     * rest of it has not arrived yet.
     *
     * @return array{0: string, 1: int}|null
     */
    private function escapeAt(int $at): ?array
    {
        $rest = substr($this->buffer, $at);

        if (2 > strlen($rest)) {
            return null;
        }

        if ('u' !== $rest[1]) {
            $decoded = json_decode('"' . substr($rest, 0, 2) . '"');

            return [true === is_string($decoded) ? $decoded : '', 2];
        }

        if (6 > strlen($rest)) {
            return null;
        }

        // A high surrogate means the character is two escapes long.
        $unit  = hexdec(substr($rest, 2, 4));
        $width = 0xD800 <= $unit && 0xDBFF >= $unit ? 12 : 6;

        if ($width > strlen($rest)) {
            return null;
        }

        $decoded = json_decode('"' . substr($rest, 0, $width) . '"');

        return [true === is_string($decoded) ? $decoded : '', $width];
    }
}
