<?php

declare(strict_types=1);

namespace App\Service\Fork\Attachment;

/**
 * FORK (FORK.md): what ForkAttachmentReader got out of one attachment — its
 * text, or pictures of it for a model that can look, or why neither.
 */
final readonly class ForkAttachmentContent
{
    /** Text, possibly cut at the limit. */
    public const string TEXT = 'text';

    /** Pictures: a photo, or the pages of a PDF without a text layer. */
    public const string IMAGES = 'images';

    /** A scan, and a model that cannot look at pictures. */
    public const string SCAN = 'scan';

    /** No reader for this kind of file (a ZIP, a CAD drawing, …). */
    public const string UNSUPPORTED = 'unsupported';

    /** Encrypted, too large, or the reader failed on it. */
    public const string FAILED = 'failed';

    /**
     * @param list<string> $images data: URIs, JPEG
     */
    public function __construct(
        public int     $partId,
        public string  $name,
        public string  $status,
        public ?string $text = null,
        public array   $images = [],
        public ?int    $pages = null,
        public bool    $truncated = false,
    ) {
    }

    public function isReadable(): bool
    {
        return self::TEXT === $this->status || self::IMAGES === $this->status;
    }

    /** @return array{id: int, name: string, status: string, pages: ?int, truncated: bool} */
    public function summary(): array
    {
        return [
            'id'        => $this->partId,
            'name'      => $this->name,
            'status'    => $this->status,
            'pages'     => $this->pages,
            'truncated' => $this->truncated,
        ];
    }
}
