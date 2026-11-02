<?php

declare(strict_types=1);

namespace App\Service\Fork\Search;

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
 * Off unless FORK_RERANK_URL is set: the full address of a /rerank endpoint in
 * the Cohere/Jina shape — POST {query, documents, top_n, instruction}, answer
 * {results: [{index, relevance_score}]}. An environment variable rather than a
 * setting, because it is an address on the operator's own network and belongs
 * to the deployment, not to the database.
 *
 * Every failure is "no ranking": the panels it feeds simply fall back to the
 * search's own order. A slow model must not hold a search page, so the timeout
 * is short.
 */
final readonly class ForkReranker
{
    private const float TIMEOUT = 4.0;

    /** Qwen3-Reranker is trained with an instruction; this one says what a "match" is here. */
    private const string INSTRUCTION = 'Given a search someone typed into their own mailbox, judge whether this email is one they are looking for or that answers what they want to know';

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface     $logger,
        #[Autowire('%env(default::FORK_RERANK_URL)%')]
        private ?string             $url = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->url && '' !== trim($this->url);
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
        if (false === $this->isAvailable() || '' === trim($query) || [] === $documents) {
            return null;
        }

        $keys = array_keys($documents);

        try {
            $response = $this->http->request('POST', trim((string) $this->url), [
                'json'    => [
                    'query'       => $query,
                    'documents'   => array_values($documents),
                    'top_n'       => count($documents),
                    'instruction' => self::INSTRUCTION,
                ],
                'timeout'      => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('ForkReranker: no ranking', ['error' => $e->getMessage()]);

            return null;
        }

        $scores = [];

        foreach ((array) ($data['results'] ?? []) as $result) {
            $index = $result['index'] ?? null;
            $score = $result['relevance_score'] ?? null;

            if (true === is_int($index) && true === isset($keys[$index]) && (true === is_float($score) || true === is_int($score))) {
                $scores[$keys[$index]] = (float) $score;
            }
        }

        if ([] === $scores) {
            return null;
        }

        arsort($scores);

        return $scores;
    }
}
