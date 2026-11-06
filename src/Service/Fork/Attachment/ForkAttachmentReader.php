<?php

declare(strict_types=1);

namespace App\Service\Fork\Attachment;

use App\Domain\Helper\PdfAttachment;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\AttachmentResolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * FORK (FORK.md): the text of an attachment, for the "ask the attachments"
 * chat (ForkAttachmentChat).
 *
 *   - PDF: `pdftotext -layout` (poppler-utils, in the image for this), one
 *     marker per page so the model can say where it read something. A PDF
 *     with (almost) no text is a scan: its first pages are rendered with
 *     `pdftoppm` and sent as pictures, when the model can look at them.
 *   - Word, Excel, PowerPoint and their OpenDocument cousins: the XML inside
 *     the ZIP, tags stripped, paragraphs and cells kept apart.
 *   - Plain text, CSV, HTML, JSON, XML, calendar files: as they are.
 *   - JPEG, PNG, WebP, GIF: the picture itself, scaled down.
 *
 * Nothing here writes next to the attachment. The bytes behind a part never
 * change, so the text is cached by part id; pictures are made again each time
 * (a second or so for six pages) rather than parking megabytes in the cache.
 *
 * A sealed part (encrypted in the browser, see MessagePart::$sealed) is never
 * opened.
 */
final readonly class ForkAttachmentReader
{
    /** Above this the file is not opened: a 200 MB plan set is not a question. */
    private const int MAX_FILE_BYTES = 40 * 1024 * 1024;

    /** Per document, in characters: about 40 000 tokens. */
    public const int MAX_TEXT = 150_000;

    /** Inside an office ZIP, per entry: a guard against a ZIP bomb. */
    private const int MAX_XML_BYTES = 60 * 1024 * 1024;

    /** Pages of a scan sent as pictures. */
    public const int SCAN_PAGES = 6;

    /** Longest edge of a picture sent to the model, in pixels. */
    private const int IMAGE_EDGE = 1600;

    /** Below this many letters per page a PDF counts as a scan. */
    private const int SCAN_LETTERS_PER_PAGE = 40;

    private const int PROCESS_SECONDS = 60;

    private const int CACHE_SECONDS = 604_800;

    private const array IMAGE_TYPES = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif'];

    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const array TEXT_EXTENSIONS = ['txt', 'csv', 'tsv', 'md', 'log', 'json', 'xml', 'html', 'htm', 'ics', 'vcf', 'eml'];

    private const array OFFICE_EXTENSIONS = ['docx', 'docm', 'xlsx', 'xlsm', 'pptx', 'odt', 'ods', 'odp'];

    public function __construct(
        private AttachmentResolver     $attachments,
        private CacheItemPoolInterface $cache,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * @param bool $pictures whether the model can look at pictures; without,
     *                       a photo is unsupported and a scan says so
     */
    public function read(MessagePart $part, bool $pictures): ForkAttachmentContent
    {
        $id   = (int) $part->id;
        $name = '' !== trim((string) $part->filename) ? trim((string) $part->filename) : 'attachment';

        if (true === $part->sealed) {
            return new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED);
        }

        $kind = $this->kindOf($part);

        if (null === $kind || ('image' === $kind && false === $pictures)) {
            return new ForkAttachmentContent($id, $name, ForkAttachmentContent::UNSUPPORTED);
        }

        if (null !== $part->size && self::MAX_FILE_BYTES < $part->size) {
            return new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED);
        }

        $item = $this->cache->getItem('fork_attachment_text_v1_' . $id);

        try {
            if ('image' === $kind) {
                $image = $this->picture($this->path($part));

                return null === $image
                    ? new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED)
                    : new ForkAttachmentContent($id, $name, ForkAttachmentContent::IMAGES, images: [$image]);
            }

            /** @var array{text: string, pages: ?int}|null $cached */
            $cached = true === $item->isHit() && is_array($item->get()) ? $item->get() : null;

            if (null === $cached) {
                $path   = $this->path($part);
                $cached = match ($kind) {
                    'pdf'    => $this->pdfText($path),
                    'office' => ['text' => $this->officeText($path, $this->extension($part)), 'pages' => null],
                    default  => ['text' => $this->plainText($path, $part), 'pages' => null],
                };

                $item->set($cached);
                $item->expiresAfter(self::CACHE_SECONDS);
                $this->cache->save($item);
            }

            if ('pdf' === $kind && true === $this->isScan($cached['text'], $cached['pages'])) {
                if (false === $pictures) {
                    return new ForkAttachmentContent($id, $name, ForkAttachmentContent::SCAN, pages: $cached['pages']);
                }

                $images = $this->pdfPictures($this->path($part));

                return [] === $images
                    ? new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED, pages: $cached['pages'])
                    : new ForkAttachmentContent($id, $name, ForkAttachmentContent::IMAGES, images: $images, pages: $cached['pages'], truncated: (int) $cached['pages'] > count($images));
            }

            $text = trim($cached['text']);

            if ('' === $text) {
                return new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED, pages: $cached['pages']);
            }

            $truncated = self::MAX_TEXT < mb_strlen($text);

            return new ForkAttachmentContent(
                $id,
                $name,
                ForkAttachmentContent::TEXT,
                text: true === $truncated ? mb_substr($text, 0, self::MAX_TEXT) : $text,
                pages: $cached['pages'],
                truncated: $truncated,
            );
        } catch (\Throwable $e) {
            // The name and the reason, never the content.
            $this->logger->warning('ForkAttachmentReader: could not read an attachment', [
                'partId' => $id,
                'kind'   => $kind,
                'error'  => $e->getMessage(),
            ]);

            return new ForkAttachmentContent($id, $name, ForkAttachmentContent::FAILED);
        }
    }

    /** 'pdf', 'office', 'text', 'image' or null — from the stated type, then the name. */
    public function kindOf(MessagePart $part): ?string
    {
        if (true === PdfAttachment::matches($part->contentType, $part->filename)) {
            return 'pdf';
        }

        $type      = strtolower(trim(explode(';', (string) $part->contentType)[0]));
        $extension = $this->extension($part);

        if (true === in_array($extension, self::OFFICE_EXTENSIONS, true)) {
            return 'office';
        }

        if (true === in_array($type, self::IMAGE_TYPES, true) || true === in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }

        if (true === str_starts_with($type, 'text/')
            || true === in_array($type, ['application/json', 'application/xml', 'message/rfc822'], true)
            || true === in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return 'text';
        }

        return null;
    }

    private function path(MessagePart $part): string
    {
        $path = $this->attachments->absolutePathFor($part);

        if (false === is_file($path) || false === is_readable($path)) {
            throw new \RuntimeException('The attachment is not on disk.');
        }

        if (self::MAX_FILE_BYTES < (int) filesize($path)) {
            throw new \RuntimeException('The attachment is too large.');
        }

        return $path;
    }

    private function extension(MessagePart $part): string
    {
        return strtolower(pathinfo((string) $part->filename, \PATHINFO_EXTENSION));
    }

    // ── PDF ───────────────────────────────────────────────────────────────

    /** @return array{text: string, pages: ?int} */
    private function pdfText(string $path): array
    {
        $process = new Process(['pdftotext', '-enc', 'UTF-8', '-layout', '-q', $path, '-']);
        $process->setTimeout(self::PROCESS_SECONDS);
        $process->run();

        if (false === $process->isSuccessful()) {
            throw new \RuntimeException('pdftotext failed: ' . trim($process->getErrorOutput()));
        }

        // pdftotext ends every page with a form feed, the last one included.
        $pages = explode("\f", $this->utf8($process->getOutput()));

        if ('' === trim((string) end($pages))) {
            array_pop($pages);
        }

        $text = '';

        foreach ($pages as $index => $page) {
            // -layout pads columns with runs of spaces; three keep a table a table.
            $page  = (string) preg_replace('/[ \t]{3,}/', '   ', $page);
            $page  = (string) preg_replace('/\n{3,}/', "\n\n", $page);
            $text .= sprintf("\n--- Seite %d ---\n%s\n", $index + 1, rtrim($page));
        }

        return ['text' => trim($text), 'pages' => count($pages)];
    }

    private function isScan(string $text, ?int $pages): bool
    {
        $letters = preg_match_all('/\p{L}/u', (string) preg_replace('/--- Seite \d+ ---/', '', $text));

        return (int) $letters < self::SCAN_LETTERS_PER_PAGE * max(1, (int) $pages);
    }

    /** @return list<string> */
    private function pdfPictures(string $path): array
    {
        $dir = sys_get_temp_dir() . '/fork-ask-' . bin2hex(random_bytes(8));

        if (false === @mkdir($dir, 0700)) {
            return [];
        }

        try {
            $process = new Process([
                'pdftoppm', '-jpeg', '-jpegopt', 'quality=80', '-r', '110',
                '-scale-to', (string) self::IMAGE_EDGE, '-f', '1', '-l', (string) self::SCAN_PAGES,
                $path, $dir . '/page',
            ]);
            $process->setTimeout(self::PROCESS_SECONDS);
            $process->run();

            $files = glob($dir . '/page*.jpg') ?: [];
            natsort($files);

            $images = [];

            foreach ($files as $file) {
                $bytes = file_get_contents($file);

                if (false !== $bytes && '' !== $bytes) {
                    $images[] = 'data:image/jpeg;base64,' . base64_encode($bytes);
                }
            }

            return $images;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }
    }

    // ── Pictures ──────────────────────────────────────────────────────────

    /** A JPEG data: URI no larger than IMAGE_EDGE, or null when GD cannot read it. */
    private function picture(string $path): ?string
    {
        $info = @getimagesize($path);

        // Checked from the header before decoding, as AttachmentThumbnailer does:
        // a few KB of PNG can expand to gigabytes of bitmap.
        if (false === $info || 40_000_000 < $info[0] * $info[1] || false === function_exists('imagecreatefromstring')) {
            return null;
        }

        $bytes = file_get_contents($path);
        $image = false === $bytes ? false : @imagecreatefromstring($bytes);

        if (false === $image) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1.0, self::IMAGE_EDGE / max($width, $height));

        if (1.0 > $scale) {
            $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            $image  = false === $scaled ? $image : $scaled;
        }

        ob_start();
        imagejpeg($image, null, 82);
        $jpeg = (string) ob_get_clean();

        return '' === $jpeg ? null : 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    // ── Office ────────────────────────────────────────────────────────────

    private function officeText(string $path, string $extension): string
    {
        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new \RuntimeException('Not a readable ZIP.');
        }

        try {
            return match ($extension) {
                'docx', 'docm' => $this->wordText($zip),
                'xlsx', 'xlsm' => $this->sheetText($zip),
                'pptx'         => $this->slideText($zip),
                default        => $this->openDocumentText($zip),
            };
        } finally {
            $zip->close();
        }
    }

    private function wordText(\ZipArchive $zip): string
    {
        // Paragraph properties hold tab STOPS (<w:tab w:pos=…/>), not tabs.
        $xml = (string) preg_replace('#<w:pPr>.*?</w:pPr>#s', '', $this->entry($zip, 'word/document.xml'));

        return $this->xmlToText($xml, [
            '</w:p>'   => "\n",
            '<w:tab/>' => "\t",
            '<w:br/>'  => "\n",
            '</w:tc>'  => "\t",
            '</w:tr>'  => "\n",
        ]);
    }

    private function slideText(\ZipArchive $zip): string
    {
        $slides = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (1 === preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = $name;
            }
        }

        ksort($slides);
        $text = '';

        foreach ($slides as $number => $name) {
            $text .= sprintf("\n--- Folie %d ---\n%s\n", $number, $this->xmlToText($this->entry($zip, $name), ['</a:p>' => "\n", '<a:br/>' => "\n"]));
        }

        return trim($text);
    }

    /** Rows as lines, cells by tabs; shared strings resolved. */
    private function sheetText(\ZipArchive $zip): string
    {
        $shared = [];

        if (false !== $zip->locateName('xl/sharedStrings.xml')) {
            $xml = $this->xml($this->entry($zip, 'xl/sharedStrings.xml'));

            foreach ($xml->si ?? [] as $si) {
                // Rich text splits one string over several runs.
                $shared[] = (string) $si->t . implode('', array_map(static fn (\SimpleXMLElement $r): string => (string) $r->t, iterator_to_array($si->r ?? [], false)));
            }
        }

        $sheets = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (1 === preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
                $sheets[(int) $m[1]] = $name;
            }
        }

        ksort($sheets);
        $text = '';

        foreach ($sheets as $number => $name) {
            $xml   = $this->xml($this->entry($zip, $name));
            $lines = [];

            foreach ($xml?->sheetData->row ?? [] as $row) {
                $cells = [];

                foreach ($row->c as $cell) {
                    $type    = (string) $cell['t'];
                    $value   = (string) $cell->v;
                    $cells[] = match ($type) {
                        's'       => $shared[(int) $value] ?? '',
                        'inlineStr' => (string) $cell->is->t,
                        default   => $value,
                    };
                }

                if ('' !== trim(implode('', $cells))) {
                    $lines[] = implode("\t", $cells);
                }
            }

            $text .= sprintf("\n--- Tabelle %d ---\n%s\n", $number, implode("\n", $lines));
        }

        return trim($text);
    }

    private function openDocumentText(\ZipArchive $zip): string
    {
        return $this->xmlToText($this->entry($zip, 'content.xml'), [
            '</text:p>'             => "\n",
            '</text:h>'             => "\n",
            '<text:tab/>'           => "\t",
            '<text:line-break/>'    => "\n",
            '<text:s/>'             => ' ',
            '</table:table-cell>'   => "\t",
            '</table:table-row>'    => "\n",
            '</draw:page>'          => "\n\n",
        ]);
    }

    private function entry(\ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if (false === $stat || self::MAX_XML_BYTES < (int) $stat['size']) {
            throw new \RuntimeException(sprintf('Missing or oversized entry %s.', $name));
        }

        $content = $zip->getFromName($name);

        if (false === $content) {
            throw new \RuntimeException(sprintf('Unreadable entry %s.', $name));
        }

        return $content;
    }

    private function xml(string $content): ?\SimpleXMLElement
    {
        // No network, no entity expansion: the file is a stranger's.
        $xml = @simplexml_load_string($content, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_COMPACT);

        return false === $xml ? null : $xml;
    }

    /** @param array<string, string> $breaks closing tags that end a line or a cell */
    private function xmlToText(string $xml, array $breaks): string
    {
        // Self-closing tags come in either spelling.
        $xml  = (string) preg_replace('#<([\w:]+)(\s[^>]*)?\s*/>#', '<$1/>', $xml);
        $text = strip_tags(strtr($xml, $breaks));
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    // ── Text ──────────────────────────────────────────────────────────────

    private function plainText(string $path, MessagePart $part): string
    {
        $text = $this->utf8((string) file_get_contents($path, false, null, 0, self::MAX_TEXT * 4));
        $type = strtolower((string) $part->contentType);

        if (true === str_contains($type, 'html') || true === in_array($this->extension($part), ['html', 'htm'], true)) {
            $text = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $text);
            $text = (string) preg_replace('#<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $text = (string) preg_replace("/[ \t]+/", ' ', $text);
            $text = (string) preg_replace("/\n\s*\n+/", "\n\n", $text);
        }

        return $text;
    }

    private function utf8(string $text): string
    {
        if (true === mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Mail from this part of the world that is not UTF-8 is Windows-1252.
        return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
