<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

/**
 * FORK (FORK.md 27): one project as Projektakte lists it.
 */
final readonly class ForkProject
{
    /**
     * @param string|null $url the project's page in Projektakte, http(s) only
     */
    public function __construct(
        public string  $number,
        public string  $name,
        public ?string $url = null,
    ) {
    }

    /**
     * From one entry of Projektakte's answer, or null for one without a number.
     *
     * @param array<mixed> $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $number = $entry['number'] ?? null;

        if (false === is_string($number) || '' === trim($number) || 64 < mb_strlen(trim($number))) {
            return null;
        }

        $name = $entry['name'] ?? '';

        return new self(trim($number), true === is_string($name) ? trim($name) : '', self::link($entry['url'] ?? null));
    }

    /**
     * A link only if it is a web address: the page puts it in an href, and
     * whatever Projektakte says is not a reason to put a javascript: there.
     */
    public static function link(mixed $url): ?string
    {
        if (false === is_string($url) || 1 !== preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }
}
