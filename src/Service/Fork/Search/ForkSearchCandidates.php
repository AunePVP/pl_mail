<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Domain\DTO\ParsedSearchQuery;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Ai\SemanticQuery;
use App\Service\Search\SearchResultHighlights;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * FORK (FORK.md): the first results of a search, as the panels above the list
 * read them — with sender and matching fragment, and ordered by the reranker
 * when there is one.
 *
 * One place for both panels, so "Beste Treffer" and the chat model's answer
 * see the same results in the same order. The search runs as the signed-in
 * user through the same repository method the list uses, so nothing here can
 * see a message the list could not.
 *
 * The reranker's order is cached per user, query and result set for a few
 * minutes: both frames ask for it within a second of each other, and the
 * second should not pay for the model again. The cache's own stampede lock
 * makes the second wait for the first rather than ask in parallel. A failure is
 * kept too, for a minute: the answer's stream and its reload come seconds
 * later, and each would otherwise wait out the timeout again.
 */
final readonly class ForkSearchCandidates
{
    /** What the reranker gets to choose from; the page shows fifty too. */
    private const int POOL = 50;

    /**
     * What each of the search chat's searches asks for. Fifty, not fewer: the
     * repository scans every body for a one-word search that fills less than
     * a page of fifty (searchPage(), SEARCH_RESCUE_BELOW), and a page of
     * twenty-five never fills one — "bestellt" took 3.4 s that way on the
     * production mailbox, with 327 results the index had found in 0.1 s.
     */
    private const int PER_SEARCH = 50;

    /** The search chat's pool; its model reads all of them. */
    private const int CHAT_POOL = 40;

    private const int SNIPPET = 300;

    private const int CACHE_SECONDS = 600;

    /** How long "the reranker did not answer" stands for one search. */
    private const int FAILURE_SECONDS = 60;

    /** How quickly age discounts a reranked result; see weighted(). */
    private const float AGE_DAYS = 45.0;

    public function __construct(
        private SemanticQuery           $semantic,
        private MessageThreadRepository $threads,
        private SearchResultHighlights  $highlights,
        private Connection              $connection,
        private ForkReranker            $reranker,
        private CacheInterface          $cache,
    ) {
    }

    /**
     * @return list<ForkCandidate> best first when reranked, else in the search's own order
     */
    public function for(User $user, ParsedSearchQuery $parsed, string $raw): array
    {
        $threads = $this->search($user, $parsed, self::POOL, true);

        if ([] === $threads) {
            return [];
        }

        return $this->ranked($user, $raw, $parsed->freeText, $this->candidates($threads, $this->highlights->forPage($threads, $parsed->freeText)));
    }

    /**
     * The results of several searches as one pool, newest first — for the
     * search chat (ForkSearchChat), whose searches the chat model wrote and
     * whose model reads the whole pool.
     *
     * Taken in turns, the first of each search, then the second of each, so
     * a broad search cannot fill the pool before a narrow one has had a place.
     * Each result's fragment is marked with the words of the search that
     * found it.
     *
     * Words only, and not reranked. The model has already turned the question
     * into the words such a mail contains, and what the embedding found for
     * the sentence was mostly newsletters that share its mood. The reranker
     * chose fifteen of thirty for the model to read and took 2–3 s to do it;
     * the model reads forty in less, judges them better, and the reranker
     * knew nothing of time — "was habe ich als letztes bestellt" is the
     * newest orders, and it left a week's out (2026-11-04).
     *
     * `$oldest` takes each search's oldest results rather than its newest,
     * for "wann bin ich das erste Mal …": the newest of thirty-seven FlixBus
     * mails did not reach back to the first trip. The pool is then oldest
     * first.
     *
     * `$byRank` takes each search's best matches rather than its newest, for a
     * search the chat has already narrowed to days that are over: within
     * 2023, "hotel or … or booking" found forty December newsletters that
     * said "booking", and not the hotel (2026-11-04). The pool is still
     * newest first — the model reads all of it.
     *
     * @param list<ParsedSearchQuery> $searches
     *
     * @return list<ForkCandidate>
     */
    public function forSearches(User $user, array $searches, bool $oldest = false, bool $byRank = false): array
    {
        $lists = [];

        foreach ($searches as $search) {
            $lists[] = [
                true === $oldest
                    ? $this->oldest($user, $search, self::PER_SEARCH)
                    : $this->search($user, $search, self::PER_SEARCH, false, true === $byRank ? SearchSortOrder::Relevance : SearchSortOrder::Recent),
                $search->freeText,
            ];
        }

        /** @var array<int, array{MessageThread, string}> $found */
        $found = [];

        for ($i = 0; $i < self::PER_SEARCH && self::CHAT_POOL > count($found); ++$i) {
            foreach ($lists as [$threads, $terms]) {
                $thread = $threads[$i] ?? null;

                if (null !== $thread && self::CHAT_POOL > count($found)) {
                    $found[(int) $thread->id] ??= [$thread, $terms];
                }
            }
        }

        if ([] === $found) {
            return [];
        }

        $byTerms = [];

        foreach ($found as [$thread, $terms]) {
            $byTerms[$terms][] = $thread;
        }

        $highlights = [];

        foreach ($byTerms as $terms => $group) {
            $highlights += $this->highlights->forPage($group, (string) $terms);
        }

        $threads = array_values(array_map(static fn (array $f): MessageThread => $f[0], $found));

        usort($threads, static fn (MessageThread $a, MessageThread $b): int => true === $oldest
            ? $a->lastMessageAt <=> $b->lastMessageAt
            : $b->lastMessageAt <=> $a->lastMessageAt);

        return array_values($this->candidates($threads, $highlights));
    }

    /**
     * A search's oldest results, oldest first. The search sorts newest first
     * and knows no other order by date, so this asks for its last pages: the
     * first call says how many there are.
     *
     * @return list<MessageThread>
     */
    private function oldest(User $user, ParsedSearchQuery $parsed, int $limit): array
    {
        $first = $this->threads->searchPage($user, $parsed, 1, $limit, sort: SearchSortOrder::Recent);
        $pages = (int) ceil($first->total / $limit);
        $found = $first->threads;

        if (1 < $pages) {
            $found = $this->threads->searchPage($user, $parsed, $pages, $limit, sort: SearchSortOrder::Recent)->threads;

            // A last page of three is three; the one before makes it a page.
            if (count($found) < $limit) {
                $found = [...$this->threads->searchPage($user, $parsed, $pages - 1, $limit, sort: SearchSortOrder::Recent)->threads, ...$found];
            }
        }

        $threads = array_values(array_filter($found, static fn (mixed $t): bool => $t instanceof MessageThread && null !== $t->id));

        return array_slice(array_reverse($threads), 0, $limit);
    }

    /**
     * @return list<MessageThread>
     */
    private function search(User $user, ParsedSearchQuery $parsed, int $limit, bool $withMeaning, ?SearchSortOrder $sort = null): array
    {
        $semantic = true === $withMeaning ? $this->semantic->forQuery($user, $parsed->freeText) : null;
        $page     = $this->threads->searchPage($user, $parsed, 1, $limit, sort: $sort ?? $user->searchSortOrder, semantic: $semantic);

        return array_values(array_filter($page->threads, static fn (mixed $t): bool => $t instanceof MessageThread && null !== $t->id));
    }

    /**
     * @param list<MessageThread>                                $threads
     * @param array<int, array{subject: mixed, snippet: mixed}> $highlights SearchResultHighlights::forPage()
     *
     * @return array<int, ForkCandidate>
     */
    private function candidates(array $threads, array $highlights): array
    {
        $senders    = $this->senders($threads);
        $candidates = [];

        foreach ($threads as $thread) {
            $id      = (int) $thread->id;
            $snippet = $highlights[$id]['snippet'] ?? null;

            $candidates[$id] = new ForkCandidate(
                thread:  $thread,
                sender:  $senders[$id] ?? null,
                snippet: null === $snippet ? null : mb_substr(trim(html_entity_decode(strip_tags((string) $snippet))), 0, self::SNIPPET),
            );
        }

        return $candidates;
    }

    /**
     * @param array<int, ForkCandidate> $candidates
     *
     * @return list<ForkCandidate>
     */
    private function ranked(User $user, string $raw, string $query, array $candidates): array
    {
        $scores = $this->scores($user, $raw, $query, $candidates);

        if (null === $scores) {
            return array_values($candidates);
        }

        $ranked = [];

        foreach ($scores as $id => $score) {
            if (true === isset($candidates[$id])) {
                $ranked[] = $candidates[$id]->withScore($score);
                unset($candidates[$id]);
            }
        }

        // The reranker judges words and knows nothing of time: asked for
        // "Flug", it put July's bookings above last week's. Recent mail is
        // what a search is usually after, so age discounts the order — not
        // the score, which still decides alone whether a result is shown.
        $now = time();

        usort($ranked, static fn (ForkCandidate $a, ForkCandidate $b): int => self::weighted($b, $now) <=> self::weighted($a, $now));

        // Anything the reranker left out keeps its place behind what it ranked.
        return array_merge($ranked, array_values($candidates));
    }

    /**
     * @param array<int, ForkCandidate> $candidates
     *
     * @return array<int, float>|null
     */
    private function scores(User $user, string $raw, string $freeText, array $candidates): ?array
    {
        if (false === $this->reranker->isAvailable()) {
            return null;
        }

        $key = 'fork_search_rerank_' . hash('sha256', implode("\0", [
            (string) $user->id,
            $raw,
            implode(',', array_map(static fn (ForkCandidate $c): string => $c->thread->id . '@' . $c->thread->lastMessageAt?->getTimestamp(), $candidates)),
        ]));

        return $this->cache->get($key, function (ItemInterface $item) use ($freeText, $candidates): ?array {
            $item->expiresAfter(self::CACHE_SECONDS);

            $scores = $this->reranker->rank($freeText, array_map(
                static fn (ForkCandidate $c): string => $c->document(),
                $candidates,
            ));

            if (null === $scores) {
                $item->expiresAfter(self::FAILURE_SECONDS);
            }

            return $scores;
        });
    }

    /**
     * The score, discounted by age to at most half: a month old keeps ~0.8 of
     * it, four months ~0.5. Mild on purpose — an old mail that answers the
     * question still beats a new one that does not.
     */
    private static function weighted(ForkCandidate $candidate, int $now): float
    {
        $at   = $candidate->thread->lastMessageAt?->getTimestamp() ?? 0;
        $days = max(0, ($now - $at) / 86400);

        return (float) $candidate->score * (0.5 + 0.5 * exp(-$days / self::AGE_DAYS));
    }

    /**
     * The sender of each thread's newest message.
     *
     * @param list<MessageThread> $threads
     *
     * @return array<int, string>
     */
    private function senders(array $threads): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (thread_id) thread_id, coalesce(nullif(from_name, \'\'), from_address) AS sender
               FROM message
              WHERE thread_id IN (:ids)
              ORDER BY thread_id, received_at DESC NULLS LAST',
            ['ids' => array_map(static fn (MessageThread $t): int => (int) $t->id, $threads)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $senders = [];

        foreach ($rows as $row) {
            $senders[(int) $row['thread_id']] = trim((string) $row['sender']);
        }

        return $senders;
    }
}
