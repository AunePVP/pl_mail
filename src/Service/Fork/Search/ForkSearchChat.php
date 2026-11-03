<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use App\Service\Search\SearchQueryParser;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FORK (FORK.md): the search chat — a conversation with the chat model about
 * the mailbox, opened by the "KI-Suche" button next to the search box.
 *
 * One turn is three steps, each reported to the page as it happens:
 *
 *   1. ForkSearchPlanner reads the conversation and the new message and
 *      writes the searches (`search` frame).
 *   2. ForkSearchCandidates runs them as the signed-in user, through the
 *      repository method the results list uses, and the reranker orders what
 *      they found by the question (`results` frame). A message the earlier
 *      results already answer is not searched again.
 *   3. The model answers from those results and the calendar — every entry
 *      in the days the message asks about, and those its words match —
 *      streamed (`token` frames), and names the mails worth opening (`done`
 *      frame).
 *
 * What the model reads is what the search panels give it: subject, sender,
 * date and the matching fragment, never whole mails. Its answer is trusted
 * for prose and for ids it was given, nothing else.
 *
 * The conversation is kept in the cache for some hours under a random id and
 * the user's id — no table, and nothing anybody else can ask for: the id alone
 * opens nothing.
 */
final readonly class ForkSearchChat
{
    /** What the model reads per turn. */
    private const int RESULTS = 15;

    private const int IMPORTANT = 5;

    /** Mails per calendar entry the answer may link: the booking and a change or two. */
    private const int TRIP_MAIL = 3;

    private const int MAX_MESSAGE = 500;

    private const int MAX_ANSWER = 1500;

    /** Turns kept, both sides counted; the planner sees fewer. */
    private const int MAX_TURNS = 12;

    private const int CACHE_SECONDS = 21_600;

    private const float TEMPERATURE = 0.2;

    private const string ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private AiAssistant            $ai,
        private ForkSearchPlanner      $planner,
        private ForkSearchCandidates   $candidates,
        private ForkTripFinder         $trips,
        private SearchQueryParser      $parser,
        private UserTimezoneResolver   $timezones,
        private CacheItemPoolInterface $cache,
        private UrlGeneratorInterface  $urls,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->ai->isEnabledFor(AiFeature::Summary);
    }

    /**
     * One turn, as the frames the page reads: `conversation` (the id to send
     * with the next message), `search`, `results`, `token`…, then `done` or
     * `error`. Stopping the iteration stops the model.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function turn(User $user, ?string $conversation, string $message): \Generator
    {
        $message = mb_substr(trim($message), 0, self::MAX_MESSAGE);

        if ('' === $message || false === $this->isAvailable()) {
            yield ['type' => 'error'];

            return;
        }

        $id    = null !== $conversation && 1 === preg_match(self::ID_PATTERN, $conversation) ? $conversation : bin2hex(random_bytes(16));
        $state = $this->load($user, $id);

        yield ['type' => 'conversation', 'id' => $id];

        $zone  = $this->timezones->resolve($user);
        $today = new DateTimeImmutable('now', $zone);
        $plan  = $this->planner->plan($state['turns'], $message, $today);

        if (null === $plan) {
            yield ['type' => 'error'];

            return;
        }

        yield ['type' => 'search', 'queries' => $plan->queries];

        $results = $state['results'];

        if ([] !== $plan->queries || [] === $results) {
            $results = $this->search($user, $plan, $zone);

            yield ['type' => 'results', 'items' => array_values(array_map($this->item(...), $results))];
        }

        $trips = $this->calendar($user, $plan);

        // What the answer may link: the results, and the mail each calendar
        // entry was read from — "was steht die Woche an" searches no mail,
        // and the booking is still the thing worth opening.
        $linkable = $results + $this->tripMail($trips, $zone);

        $tokens = $this->ai->chatStream(AiFeature::Summary, $this->messages($user, $state['turns'], $message, $plan, $results, $trips, $today), self::TEMPERATURE);

        if (null === $tokens) {
            yield ['type' => 'error'];

            return;
        }

        $prose = new ForkJsonFieldStream('answer');

        foreach ($tokens as $token) {
            $text = $prose->feed($token);

            if ('' !== $text) {
                yield ['type' => 'token', 'text' => $text];
            }
        }

        $result = $tokens->getReturn();

        if (false === $result->succeeded || null === $result->content) {
            yield ['type' => 'error'];

            return;
        }

        $decoded   = $this->decode($result->content);
        $answer    = mb_substr(trim(is_string($decoded['answer'] ?? null) ? $decoded['answer'] : ''), 0, self::MAX_ANSWER);
        $important = [];

        foreach ((array) ($decoded['important'] ?? []) as $threadId) {
            // "65544" is as good as 65544; anything else is no id of ours.
            $threadId = true === is_string($threadId) && 1 === preg_match('/^\d+$/', $threadId) ? (int) $threadId : $threadId;

            if (true === is_int($threadId) && true === isset($linkable[$threadId]) && self::IMPORTANT > count($important)) {
                $important[$threadId] = $this->item($linkable[$threadId]);
            }
        }

        $state['turns'][] = ['role' => 'user', 'text' => $message];
        $state['turns'][] = ['role' => 'assistant', 'text' => '' === $answer ? '—' : $answer];
        $state['turns']   = array_slice($state['turns'], -self::MAX_TURNS);
        $state['results'] = $results;
        $this->save($user, $id, $state);

        yield ['type' => 'done', 'answer' => $answer, 'important' => array_values($important)];
    }

    /**
     * @return array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}>
     */
    private function search(User $user, ForkSearchPlan $plan, DateTimeZone $zone): array
    {
        $found = $this->candidates->forSearches(
            $user,
            array_map(fn (string $query) => $this->parser->parse($query), $plan->queries),
            $plan->question,
        );

        $results = [];

        foreach (array_slice($found, 0, self::RESULTS) as $candidate) {
            $thread = $candidate->thread;
            $tid    = (int) $thread->id;

            $results[$tid] = [
                'id'      => $tid,
                'date'    => $thread->lastMessageAt?->setTimezone($zone)->format('Y-m-d'),
                'from'    => $candidate->sender,
                'subject' => trim((string) $thread->subject),
                'text'    => $candidate->snippet,
            ];
        }

        return $results;
    }

    /**
     * The calendar entries the answer may name: every one in the days the
     * message asks about ("was steht die Woche an" has no word a title
     * contains), and those its words match, by start.
     *
     * @return list<ForkTrip>
     */
    private function calendar(User $user, ForkSearchPlan $plan): array
    {
        $words = implode(' ', [$plan->question, ...array_map(fn (string $q): string => $this->parser->parse($q)->freeText, $plan->queries)]);
        $trips = [];

        $period = null !== $plan->from && null !== $plan->to ? $this->trips->between($user, $plan->from, $plan->to) : [];

        foreach ([...$period, ...$this->trips->find($user, $words)] as $trip) {
            $trips[(int) $trip->event->id] ??= $trip;
        }

        $trips = array_values($trips);
        usort($trips, static fn (ForkTrip $a, ForkTrip $b): int => $a->event->startsAt <=> $b->event->startsAt);

        return $trips;
    }

    /**
     * The conversations the calendar entries were read from, as results
     * without a fragment.
     *
     * @param list<ForkTrip> $trips
     *
     * @return array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}>
     */
    private function tripMail(array $trips, DateTimeZone $zone): array
    {
        $mail = [];

        foreach ($trips as $trip) {
            foreach (array_slice($trip->mail, 0, self::TRIP_MAIL) as $row) {
                if (null === $row['thread']) {
                    continue;
                }

                $mail[$row['thread']] ??= [
                    'id'      => $row['thread'],
                    'date'    => $row['receivedAt']?->setTimezone($zone)->format('Y-m-d'),
                    'from'    => $row['from'],
                    'subject' => trim($row['subject']),
                    'text'    => null,
                ];
            }
        }

        return $mail;
    }

    /**
     * What the page shows of a result: no fragment, it is the list's row.
     *
     * @param array{id: int, date: ?string, from: ?string, subject: string, text: ?string} $result
     *
     * @return array{id: int, date: ?string, from: ?string, subject: string, url: string}
     */
    private function item(array $result): array
    {
        return [
            'id'      => $result['id'],
            'date'    => $result['date'],
            'from'    => $result['from'],
            'subject' => $result['subject'],
            'url'     => $this->urls->generate('app_mail_thread', ['id' => $result['id']]),
        ];
    }

    /**
     * @param list<array{role: string, text: string}>                                                $turns
     * @param array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}> $results
     * @param list<ForkTrip>                                                                         $trips
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(User $user, array $turns, string $message, ForkSearchPlan $plan, array $results, array $trips, DateTimeImmutable $today): array
    {
        $zone     = $today->getTimezone();
        $language = str_starts_with(mb_strtolower((string) ($user->locale ?? 'de')), 'en') ? 'English' : 'German';

        $system = <<<PROMPT
            You help somebody find things in their own mailbox, in a conversation. With each new message you get the question as it stands on its own, today's date, the days it asks about (period, may be null), their calendar entries (every entry in that period, plus entries matching the question), what was searched for, and the emails the search found (most relevant first; subject, sender, date and a fragment, never the whole mail). Answer with ONE JSON object and nothing else, its keys in this order (the answer is shown while you write it):
            {"answer": "...", "important": [ids]}

            - answer: one to four short sentences in {$language} that answer the message with the concrete facts found in the data (dates, times, numbers, amounts, places). When the question is about what lies ahead, prefer what lies ahead. If the data does not answer it, say so in one sentence and suggest what to ask or search instead. When the question is about a period, go through the calendar entries in it first. Address the person informally ("du" in German). No greeting, nothing that is not in the data.
            - important: up to five email ids that back the answer or are worth opening, most useful first. Use only ids from the results or from a calendar entry's mails.
            PROMPT;

        $events = array_map(static fn (ForkTrip $trip): array => [
            'title'     => $trip->event->title,
            'start'     => true === $trip->event->isAllDay
                ? $trip->event->startsAt?->format('Y-m-d')
                : $trip->event->startsAt?->setTimezone($zone)->format('Y-m-d H:i'),
            'end'       => true === $trip->event->isAllDay
                ? $trip->event->endsAt?->format('Y-m-d')
                : $trip->event->endsAt?->setTimezone($zone)->format('Y-m-d H:i'),
            'location'  => $trip->event->location,
            'confirmed' => false === $trip->isOffer,
            'mails'     => array_values(array_unique(array_filter(array_map(
                static fn (array $row): ?int => $row['thread'],
                array_slice($trip->mail, 0, self::TRIP_MAIL),
            )))),
        ], $trips);

        $history = array_map(
            static fn (array $turn): array => ['role' => 'user' === $turn['role'] ? 'user' : 'assistant', 'content' => $turn['text']],
            $turns,
        );

        return [
            ['role' => 'system', 'content' => $system],
            ...$history,
            ['role' => 'user', 'content' => (string) json_encode([
                'message'      => $message,
                'question'     => $plan->question,
                'today'        => $today->format('Y-m-d (l)'),
                'period'       => null === $plan->from || null === $plan->to ? null : ['from' => $plan->from->format('Y-m-d (l)'), 'to' => $plan->to->format('Y-m-d (l)')],
                'searched_for' => $plan->queries,
                'calendar'     => $events,
                'results'      => array_values($results),
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE)],
        ];
    }

    /** @return array<mixed> */
    private function decode(string $raw): array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if (false === $start || false === $end || $end < $start) {
            // Prose without the object around it is still an answer.
            return ['answer' => $raw];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return true === is_array($decoded) ? $decoded : ['answer' => ''];
    }

    /**
     * @return array{turns: list<array{role: string, text: string}>, results: array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}>}
     */
    private function load(User $user, string $id): array
    {
        $item  = $this->cache->getItem($this->key($user, $id));
        $state = true === $item->isHit() ? $item->get() : null;

        if (false === is_array($state) || false === is_array($state['turns'] ?? null) || false === is_array($state['results'] ?? null)) {
            return ['turns' => [], 'results' => []];
        }

        /** @var array{turns: list<array{role: string, text: string}>, results: array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}>} $state */
        return $state;
    }

    /**
     * @param array{turns: list<array{role: string, text: string}>, results: array<int, array{id: int, date: ?string, from: ?string, subject: string, text: ?string}>} $state
     */
    private function save(User $user, string $id, array $state): void
    {
        $item = $this->cache->getItem($this->key($user, $id));
        $item->set($state);
        $item->expiresAfter(self::CACHE_SECONDS);
        $this->cache->save($item);
    }

    private function key(User $user, string $id): string
    {
        return 'fork_search_chat_' . hash('sha256', $user->id . "\0" . $id);
    }
}
