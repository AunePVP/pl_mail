<?php

declare(strict_types=1);

namespace App\Service\Fork\Attachment;

use App\Domain\Enum\Ai\AiProvider;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use App\Service\User\UserTimezoneResolver;
use Psr\Cache\CacheItemPoolInterface;

/**
 * FORK (FORK.md): a conversation with the chat model about the attachments
 * of one mail — "Was kostet das?", "Bis wann muss ich zahlen?", "Fass den
 * Vertrag zusammen".
 *
 * Unlike the search chat, the model reads the WHOLE document here: that is
 * the point, and the endpoint has the context for it (ForkAttachmentReader
 * caps a document at about 40 000 tokens, and this class all of them
 * together). Scans and photos go as pictures when the endpoint speaks the
 * OpenAI format; the Ollama client sends text only.
 *
 * The documents sit in the first user message, before the first question,
 * and every later turn repeats that message unchanged: the model host's
 * prefix cache then reads them once per conversation, not once per question.
 *
 * Which attachments a conversation is about is fixed at its first turn and
 * kept with it in the cache (user, message, random id), as ForkSearchChat
 * keeps its own — no table.
 */
final readonly class ForkAttachmentChat
{
    /** All documents of one conversation together, in characters. */
    private const int MAX_TEXT_TOTAL = 240_000;

    /** Pictures per conversation: a scan's pages and photos together. */
    private const int MAX_IMAGES = 8;

    private const int MAX_QUESTION = 1000;

    private const int MAX_ANSWER = 8000;

    /** Of the mail itself, for context ("anbei die Rechnung für März"). */
    private const int MAX_BODY = 2000;

    private const int MAX_TURNS = 16;

    private const int CACHE_SECONDS = 21_600;

    private const float TEMPERATURE = 0.2;

    /** A long document is a long read before the first word. */
    private const float TIMEOUT_SECONDS = 240.0;

    private const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private AiAssistant            $ai,
        private ForkAttachmentReader   $reader,
        private UserTimezoneResolver   $timezones,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->ai->isEnabledFor(AiFeature::Summary);
    }

    /**
     * The attachments of a message the chat may read: listed, not sealed.
     *
     * @return list<MessagePart>
     */
    public function partsOf(Message $message): array
    {
        $parts = [];

        foreach ($message->messageParts as $part) {
            if (true === $part->isListed() && false === $part->sealed) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * One turn: `conversation`, `documents` (what could be read), `token`…,
     * then `done` or `error`. Stopping the iteration stops the model.
     *
     * @param list<int> $partIds the attachments to ask about; empty means all
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function turn(User $user, Message $message, ?string $conversation, string $question, array $partIds): \Generator
    {
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION);

        if ('' === $question || false === $this->isAvailable()) {
            yield ['type' => 'error', 'reason' => 'off'];

            return;
        }

        $id    = null !== $conversation && 1 === preg_match(self::ID_PATTERN, $conversation) ? $conversation : bin2hex(random_bytes(16));
        $state = $this->load($user, $message, $id);

        // The documents are chosen once, at the start of a conversation.
        if (null === $state['parts']) {
            $available      = array_map(static fn (MessagePart $p): int => (int) $p->id, $this->partsOf($message));
            $chosen         = array_values(array_intersect($available, $partIds));
            $state['parts'] = [] === $chosen ? $available : $chosen;
        }

        yield ['type' => 'conversation', 'id' => $id];

        $documents = $this->documents($message, $state['parts']);

        yield ['type' => 'documents', 'items' => array_map(static fn (ForkAttachmentContent $c): array => $c->summary(), $documents)];

        if ([] === array_filter($documents, static fn (ForkAttachmentContent $c): bool => $c->isReadable())) {
            yield ['type' => 'error', 'reason' => 'unreadable'];

            return;
        }

        $tokens = $this->ai->chatStream(
            AiFeature::Summary,
            $this->messages($user, $message, $documents, $state['turns'], $question),
            self::TEMPERATURE,
            null,
            self::TIMEOUT_SECONDS,
        );

        if (null === $tokens) {
            yield ['type' => 'error', 'reason' => 'model'];

            return;
        }

        $answer = '';

        foreach ($tokens as $token) {
            $answer .= $token;

            yield ['type' => 'token', 'text' => $token];
        }

        $result = $tokens->getReturn();

        if (false === $result->succeeded) {
            yield ['type' => 'error', 'reason' => 'model'];

            return;
        }

        $answer = mb_substr(trim($answer), 0, self::MAX_ANSWER);

        $state['turns'][] = ['role' => 'user', 'text' => $question];
        $state['turns'][] = ['role' => 'assistant', 'text' => '' === $answer ? '—' : $answer];
        $state['turns']   = array_slice($state['turns'], -self::MAX_TURNS);
        $this->save($user, $message, $id, $state);

        yield ['type' => 'done', 'answer' => $answer];
    }

    /**
     * The chosen attachments, read, within the budget of the whole
     * conversation: text until MAX_TEXT_TOTAL, pictures until MAX_IMAGES.
     *
     * @param list<int> $partIds
     *
     * @return list<ForkAttachmentContent>
     */
    private function documents(Message $message, array $partIds): array
    {
        $pictures  = AiProvider::OpenAi === $this->ai->settings()->connectionFor(AiFeature::Summary)->provider;
        $textLeft  = self::MAX_TEXT_TOTAL;
        $imageLeft = self::MAX_IMAGES;
        $documents = [];

        foreach ($this->partsOf($message) as $part) {
            if (false === in_array((int) $part->id, $partIds, true)) {
                continue;
            }

            $content = $this->reader->read($part, $pictures && 0 < $imageLeft);

            if (ForkAttachmentContent::TEXT === $content->status && null !== $content->text) {
                if (0 >= $textLeft) {
                    $content = new ForkAttachmentContent($content->partId, $content->name, ForkAttachmentContent::FAILED, pages: $content->pages, truncated: true);
                } elseif (mb_strlen($content->text) > $textLeft) {
                    $content = new ForkAttachmentContent($content->partId, $content->name, ForkAttachmentContent::TEXT, mb_substr($content->text, 0, $textLeft), pages: $content->pages, truncated: true);
                }

                $textLeft -= mb_strlen((string) $content->text);
            }

            if (ForkAttachmentContent::IMAGES === $content->status) {
                $images    = array_slice($content->images, 0, $imageLeft);
                $content   = new ForkAttachmentContent($content->partId, $content->name, ForkAttachmentContent::IMAGES, images: $images, pages: $content->pages, truncated: $content->truncated || count($images) < count($content->images));
                $imageLeft -= count($images);
            }

            $documents[] = $content;
        }

        return $documents;
    }

    /**
     * @param list<ForkAttachmentContent>             $documents
     * @param list<array{role: string, text: string}> $turns
     *
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    private function messages(User $user, Message $message, array $documents, array $turns, string $question): array
    {
        $language = str_starts_with(mb_strtolower((string) ($user->locale ?? 'de')), 'en') ? 'English' : 'German';
        $today    = (new \DateTimeImmutable('now', $this->timezones->resolve($user)))->format('Y-m-d (l)');

        $system = <<<PROMPT
            You answer questions about the attachments of one email, for the person who received it. The first message holds the email (sender, date, subject, the start of its text) and the attachments: as text between <document> tags (page markers like "--- Seite 3 ---" where the file has pages) or as pictures of pages or photos. Today is {$today}.

            - Answer in {$language}, addressing the person informally ("du" in German). Concise: a few sentences or a short list, unless asked for a summary or a full list.
            - Use only what the attachments and the email say. Quote amounts, dates, deadlines, names and numbers exactly as written. If the answer is not in them, say so plainly in one sentence; never guess.
            - When there are several attachments, name the one you are reading from. When a page is known, add it in brackets, e.g. "(S. 3)".
            - If a document was cut off (truncated="true"), say that the answer may be in the part you did not get, when that matters.
            - Plain text. No markdown headings, no bold, no tables; lists as lines starting with "- ".
            PROMPT;

        $body = trim((string) preg_replace('/\s+/u', ' ', (string) $message->bodyText));

        $intro = sprintf(
            "<email>\nFrom: %s\nDate: %s\nSubject: %s\nText: %s\n</email>",
            trim(sprintf('%s <%s>', (string) $message->fromName, (string) $message->fromAddress)),
            $message->receivedAt?->setTimezone($this->timezones->resolve($user))->format('Y-m-d H:i') ?? '',
            trim((string) $message->subject),
            '' === $body ? '—' : mb_substr($body, 0, self::MAX_BODY),
        );

        $parts = [['type' => 'text', 'text' => $intro]];

        foreach ($documents as $document) {
            $attributes = sprintf(
                'name="%s"%s%s',
                htmlspecialchars($document->name, \ENT_QUOTES | \ENT_XML1),
                null !== $document->pages ? sprintf(' pages="%d"', $document->pages) : '',
                true === $document->truncated ? ' truncated="true"' : '',
            );

            if (ForkAttachmentContent::TEXT === $document->status) {
                $parts[] = ['type' => 'text', 'text' => sprintf("<document %s>\n%s\n</document>", $attributes, (string) $document->text)];

                continue;
            }

            if (ForkAttachmentContent::IMAGES === $document->status) {
                $parts[] = ['type' => 'text', 'text' => sprintf('<document %s> follows as %d picture(s), one per page in order.', $attributes, count($document->images))];

                foreach ($document->images as $image) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
                }

                continue;
            }

            $parts[] = ['type' => 'text', 'text' => sprintf('<document %s status="%s"/> (could not be read; say so if asked about it)', $attributes, $document->status)];
        }

        $first = 0 === count($turns) ? $question : ($turns[0]['text'] ?? $question);
        $parts[] = ['type' => 'text', 'text' => "Question: " . $first];

        // Text only: one string, which every client and template takes.
        $hasPictures = [] !== array_filter($parts, static fn (array $p): bool => 'image_url' === $p['type']);
        $opening     = true === $hasPictures
            ? $parts
            : implode("\n\n", array_map(static fn (array $p): string => (string) $p['text'], $parts));

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $opening],
        ];

        // The first turn's question is already in the opening message.
        foreach (array_slice($turns, 1) as $turn) {
            $messages[] = ['role' => 'user' === $turn['role'] ? 'user' : 'assistant', 'content' => $turn['text']];
        }

        if ([] !== $turns) {
            $messages[] = ['role' => 'user', 'content' => $question];
        }

        return $messages;
    }

    /**
     * @return array{parts: ?list<int>, turns: list<array{role: string, text: string}>}
     */
    private function load(User $user, Message $message, string $id): array
    {
        $item  = $this->cache->getItem($this->key($user, $message, $id));
        $state = true === $item->isHit() ? $item->get() : null;

        if (false === is_array($state) || false === is_array($state['turns'] ?? null) || false === is_array($state['parts'] ?? null)) {
            return ['parts' => null, 'turns' => []];
        }

        /** @var array{parts: list<int>, turns: list<array{role: string, text: string}>} $state */
        return $state;
    }

    /**
     * @param array{parts: ?list<int>, turns: list<array{role: string, text: string}>} $state
     */
    private function save(User $user, Message $message, string $id, array $state): void
    {
        $item = $this->cache->getItem($this->key($user, $message, $id));
        $item->set($state);
        $item->expiresAfter(self::CACHE_SECONDS);
        $this->cache->save($item);
    }

    private function key(User $user, Message $message, string $id): string
    {
        return 'fork_attachment_chat_' . hash('sha256', $user->id . "\0" . $message->id . "\0" . $id);
    }
}
