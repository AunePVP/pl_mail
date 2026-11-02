<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Domain\DTO\ParsedSearchQuery;
use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Service\Ai\AiAssistant;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * FORK (FORK.md): a short answer above the search results, from the chat model.
 *
 * The results list answers "which mail contains these words". Somebody who
 * types "Flug" is usually asking something else — when is my next flight, is
 * it confirmed, which of these 284 mails do I need — and the model can read the
 * first twenty results and the matching calendar entries and say so in two
 * sentences, point at the three mails worth opening, and say which results are
 * about the same thing.
 *
 * Loaded lazily by its own frame (ForkSearchInsightController), so the list is
 * never kept waiting for it. Behind the Summary switch: like a thread summary
 * it is read instead of the mail, and an operator who has not agreed to that
 * for one has not agreed to it for the other.
 *
 * What the model gets is what the page already shows: subject, sender, date
 * and the highlighted fragment of each result, never whole bodies — fast, and
 * nothing the person has not already been shown. With a reranker
 * (ForkReranker) those are the twenty it rates best out of fifty rather than
 * simply the newest twenty. What comes back is trusted
 * for nothing but prose and ids, and an id that was not in the list it was
 * given is dropped.
 *
 * Cached per user, query and result set, so going back to a search does not
 * cost the model again, and new mail in the results asks again.
 */
final readonly class ForkSearchAnswer
{
    private const int RESULTS = 20;

    private const int CACHE_SECONDS = 21_600;

    private const float TEMPERATURE = 0.2;

    public function __construct(
        private AiAssistant          $ai,
        private ForkSearchCandidates $candidates,
        private UserTimezoneResolver $timezones,
        private CacheInterface       $cache,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->ai->isEnabledFor(AiFeature::Summary);
    }

    /**
     * @param list<ForkTrip> $trips what ForkTripFinder found, so the answer can name it
     */
    public function answer(User $user, ParsedSearchQuery $parsed, string $raw, array $trips): ?ForkAnswer
    {
        if (false === $this->isAvailable()) {
            return null;
        }

        $candidates = array_slice($this->candidates->for($user, $parsed, $raw), 0, self::RESULTS);

        if ([] === $candidates) {
            return null;
        }

        $byId = [];

        foreach ($candidates as $candidate) {
            $byId[(int) $candidate->thread->id] = $candidate->thread;
        }

        $key = 'fork_search_answer_' . hash('sha256', implode("\0", [
            (string) $user->id,
            $raw,
            implode(',', array_map(static fn (ForkCandidate $c): string => $c->thread->id . '@' . $c->thread->lastMessageAt?->getTimestamp(), $candidates)),
            implode(',', array_map(static fn (ForkTrip $t): string => $t->event->id . '@' . $t->event->startsAt?->getTimestamp(), $trips)),
        ]));

        $decoded = $this->cache->get($key, function (ItemInterface $item) use ($user, $raw, $candidates, $trips): ?array {
            $item->expiresAfter(self::CACHE_SECONDS);

            $decoded = $this->ask($user, $raw, $candidates, $trips);

            if (null === $decoded) {
                $item->expiresAfter(1);
            }

            return $decoded;
        });

        if (null === $decoded) {
            return null;
        }

        $answer = trim((string) (is_string($decoded['answer'] ?? null) ? $decoded['answer'] : ''));

        $important = [];

        foreach ((array) ($decoded['important'] ?? []) as $id) {
            if (true === is_int($id) && true === isset($byId[$id]) && 3 > count($important)) {
                $important[$id] = $byId[$id];
            }
        }

        $groups = [];

        foreach ((array) ($decoded['groups'] ?? []) as $group) {
            if (false === is_array($group) || false === is_string($group['title'] ?? null)) {
                continue;
            }

            $members = [];

            foreach ((array) ($group['ids'] ?? []) as $id) {
                if (true === is_int($id) && true === isset($byId[$id])) {
                    $members[$id] = $byId[$id];
                }
            }

            if (2 <= count($members)) {
                $groups[] = ['title' => mb_substr(trim($group['title']), 0, 120), 'threads' => array_values($members)];
            }
        }

        if ('' === $answer && [] === $important && [] === $groups) {
            return null;
        }

        return new ForkAnswer(mb_substr($answer, 0, 800), array_values($important), array_slice($groups, 0, 5));
    }

    /**
     * @param list<ForkCandidate> $candidates
     * @param list<ForkTrip>      $trips
     *
     * @return array<string, mixed>|null
     */
    private function ask(User $user, string $raw, array $candidates, array $trips): ?array
    {
        $zone     = $this->timezones->resolve($user);
        $language = str_starts_with(mb_strtolower((string) ($user->locale ?? 'de')), 'en') ? 'English' : 'German';

        $results = [];

        foreach ($candidates as $candidate) {
            $thread = $candidate->thread;

            $results[] = [
                'id'      => (int) $thread->id,
                'date'    => $thread->lastMessageAt?->setTimezone($zone)->format('Y-m-d'),
                'from'    => $candidate->sender,
                'subject' => trim((string) $thread->subject),
                'text'    => $candidate->snippet,
                'mails'   => $thread->messageCount,
            ];
        }

        $events = array_map(static fn (ForkTrip $trip): array => [
            'title'     => $trip->event->title,
            'start'     => true === $trip->event->isAllDay
                ? $trip->event->startsAt?->format('Y-m-d')
                : $trip->event->startsAt?->setTimezone($zone)->format('Y-m-d H:i'),
            'location'  => $trip->event->location,
            'confirmed' => false === $trip->isOffer,
        ], $trips);

        $system = <<<PROMPT
            You help somebody who searched their own mailbox. You get what they typed, today's date, matching entries from their calendar, and the first search results (most relevant first). Answer with ONE JSON object and nothing else:
            {"answer": "...", "important": [ids], "groups": [{"title": "...", "ids": [ids]}]}

            - answer: one to three short sentences in {$language} that answer what they most likely want to know, with the concrete facts (dates, times, flight numbers, amounts) found in the data. Prefer what lies ahead over what is past, name things in the order they happen, and do not call anything an outbound or return trip unless the data says so. No greeting, no advice, nothing that is not in the data. Empty string if the data answers nothing.
            - important: up to three result ids worth opening first, most useful first.
            - groups: results that are about the same thing (one trip, one booking, one order, one appointment), at least two ids each, with a short title like "Flug Frankfurt → Lissabon, 12. März" in {$language}. Leave out results that stand alone.
            Use only ids from the results.
            PROMPT;

        $userMessage = json_encode([
            'search'   => $raw,
            'today'    => new DateTimeImmutable('now', $zone)->format('Y-m-d (l)'),
            'calendar' => $events,
            'results'  => $results,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);

        $raw = $this->ai->chat(AiFeature::Summary, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => (string) $userMessage],
        ], self::TEMPERATURE);

        if (null === $raw) {
            return null;
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');

        if (false === $start || false === $end || $end < $start) {
            return ['answer' => '', 'important' => [], 'groups' => []];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return true === is_array($decoded) ? $decoded : ['answer' => '', 'important' => [], 'groups' => []];
    }
}
