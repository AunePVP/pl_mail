<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

use App\Repository\Fork\ForkAiOptionsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FORK (FORK.md): a cross-encoder that orders search results by how well they
 * answer the query.
 *
 * The search ranks by words and dates; a reranker reads the query and each
 * result together and says how well one answers the other, which is what
 * "show me the mail I am looking for" means. It is a separate small model
 * (Qwen3-Reranker-0.6B here) behind its own address, so it is fast — under a
 * second for fifty results — where the chat model takes several.
 *
 * Off unless an address is set: the full address of a /rerank endpoint in the
 * Cohere/Jina shape — POST {query, documents, top_n, instruction}, answer
 * {results: [{index, relevance_score}]}. Set in Admin → AI (fork_ai_options,
 * ForkRerankExtension), or else by FORK_RERANK_URL; the admin's wins, so the
 * variable is the deployment's default and the page is where it is changed.
 *
 * Every failure is "no ranking": the panels it feeds simply fall back to the
 * search's own order. The timeout is what fifty results take on the hardware
 * this runs on, with room for a cold start, and no more: a slow model must not
 * hold a search page, and ForkSearchCandidates remembers a failure, so one
 * search pays for it at most once.
 */
final readonly class ForkReranker
{
    /**
     * Qwen3-Reranker-4B on an Arc A380: 2.7 s for fifty results with a shared
     * prefix cache, 5.9 s without (2026-11-02). Four seconds failed every ranking
     * of the second.
     */
    private const float TIMEOUT = 8.0;

    /** Qwen3-Reranker is trained with an instruction; this one says what a "match" is here. */
    private const string INSTRUCTION = 'Given a search someone typed into their own mailbox, judge whether this email is one they are looking for or that answers what they want to know';

    /** What the Test button asks: one document that matches and one that does not. */
    private const string PROBE_QUERY = 'Flug nach Hamburg';

    private const array PROBE_DOCUMENTS = ['Ihre Flugbuchung nach Hamburg ist bestätigt', 'Rezept für Apfelkuchen'];

    public function __construct(
        private HttpClientInterface      $http,
        private LoggerInterface          $logger,
        private ?ForkAiOptionsRepository $options = null,
        #[Autowire('%env(default::FORK_RERANK_URL)%')]
        private ?string                  $fallbackUrl = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->url();
    }

    /** The address in use: Admin → AI's, else FORK_RERANK_URL's, else none. */
    public function url(): ?string
    {
        $url = $this->options?->rerankUrl() ?? $this->fallbackUrl;

        return null !== $url && '' !== trim($url) ? trim($url) : null;
    }

    /**
     * Whether $url answers like a reranker, for Admin → AI's Test button: the
     * time it took and the scores of a match and a miss, or why not.
     *
     * @return array{ok: bool, ms: int, match: ?float, miss: ?float, error: ?string}
     */
    public function probe(string $url): array
    {
        $started = hrtime(true);

        try {
            $data = $this->post(trim($url), self::PROBE_QUERY, self::PROBE_DOCUMENTS);
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => self::since($started), 'match' => null, 'miss' => null, 'error' => $e->getMessage()];
        }

        $scores = $this->scores($data, [0, 1]);

        if (2 !== count($scores)) {
            return ['ok' => false, 'ms' => self::since($started), 'match' => null, 'miss' => null, 'error' => 'no {results: [{index, relevance_score}]} in the answer'];
        }

        return ['ok' => true, 'ms' => self::since($started), 'match' => $scores[0], 'miss' => $scores[1], 'error' => null];
    }

    /**
     * Scores for each document, by its key, best first; null when there is no
     * reranker or it did not answer.
     *
     * @param array<int, string> $documents keyed by whatever the caller needs back (a thread id)
     *
     * @return array<int, float>|null
     */
    public function rank(string $query, array $documents): ?array
    {
        $url = $this->url();

        if (null === $url || '' === trim($query) || [] === $documents) {
            return null;
        }

        try {
            $data = $this->post($url, $query, array_values($documents));
        } catch (\Throwable $e) {
            $this->logger->warning('ForkReranker: no ranking', ['error' => $e->getMessage()]);

            return null;
        }

        $scores = $this->scores($data, array_keys($documents));

        if ([] === $scores) {
            return null;
        }

        arsort($scores);

        return $scores;
    }

    /**
     * @param list<string> $documents
     *
     * @return array<mixed>
     */
    private function post(string $url, string $query, array $documents): array
    {
        return $this->http->request('POST', $url, [
            'json'    => [
                'query'       => $query,
                'documents'   => $documents,
                'top_n'       => count($documents),
                'instruction' => self::INSTRUCTION,
            ],
            'timeout'      => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
        ])->toArray();
    }

    /**
     * @param array<mixed>    $data
     * @param list<int|string> $keys what each document's position stands for
     *
     * @return array<int|string, float>
     */
    private function scores(array $data, array $keys): array
    {
        $scores = [];

        foreach ((array) ($data['results'] ?? []) as $result) {
            $index = $result['index'] ?? null;
            $score = $result['relevance_score'] ?? null;

            if (true === is_int($index) && true === isset($keys[$index]) && (true === is_float($score) || true === is_int($score))) {
                $scores[$keys[$index]] = (float) $score;
            }
        }

        return $scores;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
