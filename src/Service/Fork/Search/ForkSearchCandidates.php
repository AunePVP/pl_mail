<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Domain\DTO\ParsedSearchQuery;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
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
 * second should not pay for the model again.
 */
final readonly class ForkSearchCandidates
{
    /** What the reranker gets to choose from; the page shows fifty too. */
    private const int POOL = 50;

    private const int SNIPPET = 300;

    private const int CACHE_SECONDS = 600;

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
        $semantic = $this->semantic->forQuery($user, $parsed->freeText);
        $page     = $this->threads->searchPage($user, $parsed, 1, self::POOL, sort: $user->searchSortOrder, semantic: $semantic);
        $threads  = array_values(array_filter($page->threads, static fn (mixed $t): bool => $t instanceof MessageThread && null !== $t->id));

        if ([] === $threads) {
            return [];
        }

        $highlights = $this->highlights->forPage($threads, $parsed->freeText);
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

        $scores = $this->scores($user, $raw, $parsed->freeText, $candidates);

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

        usort($ranked, static fn (ForkCandidate $a, ForkCandidate $b): int
            => self::weighted($b, $now) <=> self::weighted($a, $now));

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
                $item->expiresAfter(1);
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
