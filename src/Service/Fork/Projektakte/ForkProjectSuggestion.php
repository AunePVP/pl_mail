<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

/**
 * FORK (FORK.md 27): a project Projektakte thinks a message belongs to, and why.
 * Offered in the picker, never applied: a person decides, however sure it is.
 */
final readonly class ForkProjectSuggestion
{
    /** The confidences Projektakte answers with; anything else is shown as the lowest. */
    public const array CONFIDENCES = ['sicher', 'hoch', 'niedrig'];

    public function __construct(
        public ForkProject $project,
        public string      $confidence,
        public float       $score,
        public string      $reason,
    ) {
    }

    /** @param array<mixed> $entry */
    public static function fromArray(array $entry): ?self
    {
        $project = ForkProject::fromArray($entry);

        if (null === $project) {
            return null;
        }

        $confidence = $entry['confidence'] ?? null;
        $score      = $entry['score'] ?? 0;
        $reason     = $entry['reason'] ?? '';

        return new self(
            $project,
            true === in_array($confidence, self::CONFIDENCES, true) ? $confidence : 'niedrig',
            true === is_int($score) || true === is_float($score) ? (float) $score : 0.0,
            true === is_string($reason) ? trim($reason) : '',
        );
    }
}
