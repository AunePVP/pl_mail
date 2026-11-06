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
 * how such a mail is worded and writes the search that would find it, plus
 * the question as it stands on its own — "und zurück?" after a question
 * about the outward trip is "wann fahre ich von Hamburg zurück".
 *
 * One search of alternatives rather than several of a word or two:
 * "bestellung or bestellt or order or …" in the search's own syntax
 * (FreeTextCompiler: websearch). The mail is half German and half English —
 * "was habe ich als letztes bestellt" searched "bestellung" and "bestellt",
 * and the jacket whose mail said "Order … confirmed" was not among the
 * answers. Fifteen alternatives in one statement took 0.9–1.1 s on the
 * production mailbox (2026-11-04); "bestellung", "bestellt" and "order" one
 * after another took 6.9 s. Alternatives match whole words, so the model
 * writes the forms out: no prefix arm runs for them.
 *
 * Measured against Qwen on the Spark (2026-11-03, reasoning off): about a
 * second and some forty tokens per question.
 */
final readonly class ForkSearchPlanner
{
    /** Each search is about a second, and they run one after another; one is the rule. */
    public const int MAX_QUERIES = 2;

    private const float TEMPERATURE = 0.1;

    /** A plan is some three hundred characters; four thousand is a model that does not stop. */
    private const int MAX_PLAN = 4000;

    /** Seconds; a plan takes two to three. */
    private const float TIMEOUT = 20.0;

    /** Twelve alternatives of a word or two, and room to spare. */
    private const int MAX_QUERY_LENGTH = 400;

    private const int MAX_QUESTION_LENGTH = 300;

    /** "2023 bis 2025" is a question; a span of decades is a model that did not mean a period. */
    private const string MAX_PERIOD = '+5 years';

    /** Words of a sentence about time that no mail is found by; see ownWords(). */
    private const array FILLER = [
        'diese', 'dieser', 'dieses', 'woche', 'monat', 'heute', 'morgen', 'gestern', 'jetzt', 'steht', 'stehen',
        'kommt', 'kommen', 'this', 'week', 'month', 'today',
    ];

    /** How many of the message's own words the fallback search has. */
    private const int OWN_WORDS = 4;

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
        $answer = $this->ask($this->messages($history, $message, $today));

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

        return new ForkSearchPlan($queries, $question, $from, $to, true === ($decoded['oldest'] ?? false));
    }

    /**
     * The model's plan, read as it is written and cut off once the object is
     * complete or too long to be one.
     *
     * Streamed for the cut-off, not for the page. A list of alternatives is
     * the kind of text a model can fail to stop writing: asked "und eine
     * Jacke" after a question about orders, Qwen wrote "or …" for two minutes
     * until the request timed out (2026-11-04). Cut off, the plan is whatever
     * of it parses — usually nothing, and then the message's own words.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function ask(array $messages): ?string
    {
        $tokens = $this->ai->chatStream(AiFeature::Summary, $messages, self::TEMPERATURE, timeout: self::TIMEOUT);

        if (null === $tokens) {
            return null;
        }

        $answer = '';

        foreach ($tokens as $token) {
            $answer .= $token;

            if (self::MAX_PLAN < strlen($answer) || true === self::closes($answer)) {
                return $answer;
            }
        }

        $result = $tokens->getReturn();

        return true === $result->succeeded ? $answer : null;
    }

    /** Whether the text holds a whole JSON object: the braces outside strings balance. */
    private static function closes(string $text): bool
    {
        $depth    = 0;
        $inString = false;
        $escaped  = false;

        for ($i = 0, $n = strlen($text); $i < $n; ++$i) {
            $char = $text[$i];

            if (true === $inString) {
                if (true === $escaped) {
                    $escaped = false;
                } elseif ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;
            } elseif ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char && 0 < $depth && 0 === --$depth) {
                return true;
            }
        }

        return false;
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
     * one search of alternatives — the fallback when the model wrote none.
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

        return [] === $words ? [] : [implode(' or ', array_slice($words, 0, self::OWN_WORDS))];
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
            Somebody is searching their own mailbox by talking to you. You get the conversation so far and their new message. Decide what to search for. The search matches whole words in the subject, sender and text of each email; the newest matches come first, and you will read forty of them. Answer with ONE JSON object on one line and nothing else:
            {"queries": ["...", "..."], "question": "...", "period": {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}, "oldest": false}

            - queries: usually ONE search, at most two (a second only for something else entirely). A search is alternatives joined by " or ": an email is found when it contains any one of them. An alternative is one word, or two or three words that must all occur ("hamburg flixbus or hamburg fernbus"). Write eight to twelve alternatives, no more: the senders and companies ("flixbus", "amazon"), the kinds of mail as their senders word them, and every form and compound, in BOTH German and English, because the emails are in both and an alternative matches only that exact word. For "was habe ich zuletzt bestellt": "bestellung or bestellt or bestellbestätigung or auftragsbestätigung or gekauft or versandbestätigung or order or ordered or purchase or shipped". For a bus trip to Hamburg: "hamburg flixbus or hamburg fernbus or hamburg bus or hamburg buchungsbestätigung or hamburg ticket". Every alternative must on its own point to what is asked: for one thing ("und eine Jacke?"), the words for that thing only ("jacke or jacket or …"), never generic ones like "order" or "bestellung" beside them, which find every other order too; to tie the two, write both into one alternative ("jacke bestellung"). Never question words, filler or verbs like "wann", "ist", "meine", "habe", "zeig", and never a year or a date as a word: a time goes into period. Filters, only when asked for ("von Anna", "mit Anhang"): from:<name or domain>, has:attachment, after:YYYY-MM-DD, before:YYYY-MM-DD, written once at the end of the search they narrow ("rechnung or invoice from:anna"), or alone when there are no words ("from:anna after:2026-10-27"). An empty list only when the conversation already found what the new message is about.
            - question: the new message as a question that stands on its own, in its language, with what the conversation says filled in ("und zurück?" after a bus trip to Hamburg becomes "Wann fahre ich mit dem Bus von Hamburg zurück?").
            - period: the days the message asks about, both included, counted from today: "diese Woche" is today to Sunday, "morgen" is tomorrow, "nächste Woche" is next Monday to Sunday, "was steht an" without a time is today and the next 13 days; in the past too: "im Dezember 2024" is 2024-12-01 to 2024-12-31, "2023" is the whole year, "letzten Sommer" is June to August of last year. null when the message names no time.
            - oldest: true when the message asks for the first, the earliest or since when ("das erste Mal", "seit wann", "wann zum ersten Mal"), else false.
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
