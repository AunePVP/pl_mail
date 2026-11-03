<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Ai\AiFeature;
use App\Service\Ai\AiAssistant;
use App\Service\Search\SearchQueryParser;
use DateTimeImmutable;

/**
 * FORK (FORK.md): what the search chat should search for, written by the chat
 * model from the conversation so far.
 *
 * The search matches words, and every word of a query has to be in the mail.
 * "wann ist meine Busfahrt nach Hamburg" therefore finds nothing but what
 * the meaning-based arm suggests: the booking says "Buchungsbestätigung",
 * "Flix" and "Hamburg", and no mail says "wann ist meine". The model knows
 * how such a mail is worded and writes up to three short searches that would
 * find it, plus the question as it stands on its own — "und zurück?" after a
 * question about the outward trip is "wann fahre ich von Hamburg zurück" —
 * which is what the reranker orders the results by.
 *
 * Measured against Qwen on the Spark (2026-11-03, reasoning off): about a
 * second and some forty tokens per question.
 */
final readonly class ForkSearchPlanner
{
    /**
     * Each search costs what one in the box does, 0.4 to 2.6 s on the production
     * mailbox (2026-11-03), and they run one after another.
     */
    public const int MAX_QUERIES = 3;

    private const float TEMPERATURE = 0.1;

    private const int MAX_QUERY_LENGTH = 80;

    private const int MAX_QUESTION_LENGTH = 300;

    private const string MAX_PERIOD = '+1 year';

    /** Words of a sentence about time that no mail is found by; see ownWords(). */
    private const array FILLER = [
        'diese', 'dieser', 'dieses', 'woche', 'monat', 'heute', 'morgen', 'gestern', 'jetzt', 'steht', 'stehen',
        'kommt', 'kommen', 'this', 'week', 'month', 'today',
    ];

    /** Earlier turns the model sees; enough for "und zurück?", not a whole afternoon. */
    private const int HISTORY = 6;

    public function __construct(
        private AiAssistant       $ai,
        private SearchQueryParser $parser,
    ) {
    }

    /**
     * Null when the model did not answer. A plan without queries is a message
     * the results already found can answer ("und wann bin ich da?").
     *
     * @param list<array{role: string, text: string}> $history earlier turns, oldest first
     */
    public function plan(array $history, string $message, DateTimeImmutable $today): ?ForkSearchPlan
    {
        $answer = $this->ai->chat(AiFeature::Summary, $this->messages($history, $message, $today), self::TEMPERATURE);

        if (null === $answer) {
            return null;
        }

        $decoded = $this->decode($answer);

        $question = is_string($decoded['question'] ?? null) ? trim($decoded['question']) : '';
        $question = '' === $question ? $message : mb_substr($question, 0, self::MAX_QUESTION_LENGTH);
        $queries  = $this->queries($decoded['queries'] ?? []);

        [$from, $to] = $this->period($decoded['period'] ?? null, $today);

        // A first question searches something. "welche Busfahrt habe ich
        // diese Woche" once came back with no searches at all, and the answer
        // was read from nothing. Not when it names a time: "was steht die
        // Woche an" is the calendar's to answer, and its own words found
        // whatever mail says "steht".
        if ([] === $queries && [] === $history && null === $from) {
            $queries = $this->ownWords($message);
        }

        return new ForkSearchPlan($queries, $question, $from, $to);
    }

    /**
     * The days the model says the message is about, kept to a sane span: a
     * year at most, and no date that does not parse.
     *
     * @return array{?DateTimeImmutable, ?DateTimeImmutable}
     */
    private function period(mixed $period, DateTimeImmutable $today): array
    {
        if (false === is_array($period) || false === is_string($period['from'] ?? null) || false === is_string($period['to'] ?? null)) {
            return [null, null];
        }

        $zone = $today->getTimezone();
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $period['from'], $zone);
        $to   = DateTimeImmutable::createFromFormat('!Y-m-d', $period['to'], $zone);

        if (false === $from || false === $to || $to < $from || $from->modify(self::MAX_PERIOD) < $to) {
            return [null, null];
        }

        return [$from, $to];
    }

    /**
     * The message's own words that are not question words, longest first, as
     * one search each — the fallback when the model wrote none.
     *
     * @return list<string>
     */
    private function ownWords(string $message): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => 4 <= mb_strlen($word) && false === in_array($word, [...ForkTripFinder::STOP_WORDS, ...self::FILLER], true),
        )));

        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($words, 0, self::MAX_QUERIES);
    }

    /**
     * Searches the parser can read; anything else the model wrote is dropped.
     *
     * @return list<string>
     */
    private function queries(mixed $written): array
    {
        $queries = [];

        foreach (is_array($written) ? $written : [] as $query) {
            if (false === is_string($query)) {
                continue;
            }

            $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

            if ('' === $query || self::MAX_QUERY_LENGTH < mb_strlen($query)) {
                continue;
            }

            // "from:anna after:…" alone is a search; a query of nothing the
            // parser reads is not.
            $parsed = $this->parser->parse($query);

            if ('' === trim($parsed->freeText) && true === $parsed->isEmpty()) {
                continue;
            }

            $queries[mb_strtolower($query)] ??= $query;

            if (self::MAX_QUERIES <= count($queries)) {
                break;
            }
        }

        return array_values($queries);
    }

    /** @return array<mixed> */
    private function decode(string $answer): array
    {
        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');

        if (false === $start || false === $end || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($answer, $start, $end - $start + 1), true);

        return true === is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{role: string, text: string}> $history
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(array $history, string $message, DateTimeImmutable $today): array
    {
        $system = <<<PROMPT
            Somebody is searching their own mailbox by talking to you. You get the conversation so far and their new message. Decide what to search for. The search matches whole words in the subject, sender and text of each email (every word of a query must occur; a word also matches its longer forms, "rechnung" finds "Rechnungen"). Answer with ONE JSON object and nothing else:
            {"queries": ["...", "..."], "question": "...", "period": {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}}

            - queries: one to three searches, best first, that find the emails answering the new message. Each is one to three words that literally appear in such emails: the company or sender ("flixbus", "lufthansa", "amazon"), the kind of mail as its senders word it ("buchungsbestätigung", "rechnung", "bestellung", "ticket"), places and names from the conversation. Several short queries rather than one long one. Words in the language the emails are likely written in. Never question words, filler or verbs like "wann", "ist", "meine", "habe", "zeig". Filters, only when asked for ("letzte Woche", "von Anna", "mit Anhang"; today is given): from:<name or domain>, after:YYYY-MM-DD, before:YYYY-MM-DD, has:attachment, written into the query they narrow ("rechnung from:anna", or "from:anna after:2026-10-27" when there are no words), never as a query of their own. An empty list only when the conversation already found what the new message is about.
            - question: the new message as a question that stands on its own, in its language, with what the conversation says filled in ("und zurück?" after a bus trip to Hamburg becomes "Wann fahre ich mit dem Bus von Hamburg zurück?").
            - period: the days the message asks about, both included, counted from today: "diese Woche" is today to Sunday, "morgen" is tomorrow, "nächste Woche" is next Monday to Sunday, "was steht an" without a time is today and the next 13 days. null when the message names no time.
            PROMPT;

        $turns = array_map(
            static fn (array $turn): array => ['role' => 'user' === $turn['role'] ? 'user' : 'assistant', 'content' => $turn['text']],
            array_slice($history, -self::HISTORY),
        );

        return [
            ['role' => 'system', 'content' => $system],
            ...$turns,
            ['role' => 'user', 'content' => (string) json_encode([
                'message' => $message,
                'today'   => $today->format('Y-m-d (l)'),
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
        ];
    }
}
