<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Entity\Mail\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FORK (FORK.md 27): plMail's side of Projektakte's integration API — the
 * project list, suggestions for one message, and "something changed, come and
 * look".
 *
 * Projektakte is the office's own tool beside plMail: it reads the project
 * folders and, over JMAP, the mail, and answers questions per project. A person
 * files a message under a project here, while reading it; Projektakte reads
 * that decision back from Email/get (plmail:projektakte) and offers the list
 * and its guesses through this API.
 *
 * Server to server only. The token must never reach a browser, and the page's
 * CSP allows `connect-src 'self'` anyway, so every call starts here.
 *
 * Off unless PROJEKTAKTE_URL and PROJEKTAKTE_TOKEN are both set; then the
 * feature is not drawn anywhere. Every failure — down, slow, refused, a shape
 * that is not the contract — is "Projektakte is not available right now",
 * logged without the token: a person reading mail must never see a 500 because
 * a tool on another machine is restarting. The address is set by whoever runs
 * the installation, not by a user, so this is the plain HTTP client rather than
 * the SSRF-guarded one user-supplied addresses go through.
 */
final readonly class ForkProjektakteClient
{
    /** Projektakte answers from its own database; five seconds is a host that is not there. */
    private const float TIMEOUT = 5.0;

    private const float MAX_DURATION = 10.0;

    /** notify() is fire-and-forget and must not hold the click that caused it. */
    private const float NOTIFY_TIMEOUT = 2.0;

    /** Projects appear a few times a week; a new one is also looked up directly (lookup()). */
    private const int LIST_TTL = 300;

    /** After a failure, ask again this soon, and not for every picker opened before that. */
    private const int FAILURE_TTL = 60;

    /** Reopening the picker on the same mail shows the same guesses at once. */
    private const int SUGGEST_TTL = 600;

    private const int LIST_LIMIT = 500;

    /** What the contract allows for the body Projektakte compares. */
    private const int BODY_LIMIT = 20_000;

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface      $cache,
        private LoggerInterface     $logger,
        #[Autowire('%env(default::PROJEKTAKTE_URL)%')]
        private ?string             $url = null,
        #[Autowire('%env(default::PROJEKTAKTE_TOKEN)%')]
        private ?string             $token = null,
    ) {
    }

    /** Whether the integration is configured at all — not whether Projektakte answers. */
    public function isAvailable(): bool
    {
        return null !== $this->base() && '' !== trim((string) $this->token);
    }

    /**
     * Projektakte's own description of itself, for checking a setup: whether it
     * answers, and if not, why.
     *
     * @return array{ok: bool, ms: int, info: ?array<mixed>, error: ?string}
     */
    public function info(): array
    {
        $started = hrtime(true);

        if (false === $this->isAvailable()) {
            return ['ok' => false, 'ms' => 0, 'info' => null, 'error' => 'PROJEKTAKTE_URL and PROJEKTAKTE_TOKEN are not both set'];
        }

        try {
            $info = $this->send('GET', '/api/integration/info');
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => self::since($started), 'info' => null, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'ms' => self::since($started), 'info' => $info, 'error' => null];
    }

    /**
     * Every project, newest first, by number; null when Projektakte did not
     * answer. Cached for a few minutes, a failure for one.
     *
     * @return array<string, ForkProject>|null
     */
    public function projects(): ?array
    {
        if (false === $this->isAvailable()) {
            return null;
        }

        $rows = $this->cache->get($this->key('projects'), function (ItemInterface $item): ?array {
            $data = $this->call('GET', '/api/integration/projects', ['query' => ['q' => '', 'limit' => self::LIST_LIMIT]]);

            if (null === $data || false === is_array($data['projects'] ?? null)) {
                $item->expiresAfter(self::FAILURE_TTL);

                return null;
            }

            $item->expiresAfter(self::LIST_TTL);

            return array_values(array_filter($data['projects'], 'is_array'));
        });

        if (false === is_array($rows)) {
            return null;
        }

        $projects = [];

        foreach ($rows as $row) {
            $project = ForkProject::fromArray($row);

            if (null !== $project) {
                $projects[$project->number] ??= $project;
            }
        }

        return $projects;
    }

    /**
     * One project by its number, asked of Projektakte directly: for a project
     * created since the list was cached, or one older than its first 500.
     * Null when there is no such project or Projektakte did not answer.
     */
    public function lookup(string $number): ?ForkProject
    {
        $number = trim($number);

        if (false === $this->isAvailable() || '' === $number) {
            return null;
        }

        $data = $this->call('GET', '/api/integration/projects', ['query' => ['q' => $number, 'limit' => 20]]);

        foreach ((array) ($data['projects'] ?? []) as $row) {
            $project = true === is_array($row) ? ForkProject::fromArray($row) : null;

            if (null !== $project && 0 === strcasecmp($project->number, $number)) {
                return $project;
            }
        }

        return null;
    }

    /**
     * Up to three projects this message may belong to, best first; an empty
     * list when Projektakte has no idea, null when it did not answer. Nothing
     * is stored or assigned by asking.
     *
     * @return list<ForkProjectSuggestion>|null
     */
    public function suggest(Message $message): ?array
    {
        if (false === $this->isAvailable() || null === $message->id) {
            return null;
        }

        $rows = $this->cache->get($this->key('suggest_' . $message->id), function (ItemInterface $item) use ($message): ?array {
            $data = $this->call('POST', '/api/integration/suggest', ['json' => [
                'subject'     => (string) $message->subject,
                'body'        => mb_substr(self::bodyOf($message), 0, self::BODY_LIMIT),
                'from'        => (string) $message->fromAddress,
                'attachments' => self::attachmentNames($message),
                'limit'       => 3,
            ]]);

            if (null === $data || false === is_array($data['suggestions'] ?? null)) {
                $item->expiresAfter(self::FAILURE_TTL);

                return null;
            }

            $item->expiresAfter(self::SUGGEST_TTL);

            return array_values(array_filter($data['suggestions'], 'is_array'));
        });

        if (false === is_array($rows)) {
            return null;
        }

        $suggestions = [];

        foreach (array_slice($rows, 0, 3) as $row) {
            $suggestion = ForkProjectSuggestion::fromArray($row);

            if (null !== $suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Tell Projektakte an assignment changed, so it reads Email/changes now
     * rather than at its next round. Fire-and-forget: a failure is logged and
     * nothing else — the assignment stands, and the regular round finds it.
     */
    public function notify(): void
    {
        if (false === $this->isAvailable()) {
            return;
        }

        try {
            $status = $this->http->request('POST', $this->base() . '/api/integration/notify', $this->options([
                'body'         => '{}',
                'headers'      => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                'timeout'      => self::NOTIFY_TIMEOUT,
                'max_duration' => self::NOTIFY_TIMEOUT,
            ]))->getStatusCode();

            if (300 <= $status) {
                $this->logger->warning('Projektakte: notify was refused', ['status' => $status]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Projektakte: notify did not arrive', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>|null
     */
    private function call(string $method, string $path, array $options = []): ?array
    {
        try {
            return $this->send($method, $path, $options);
        } catch (\Throwable $e) {
            $this->logger->warning('Projektakte: no answer', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, $this->base() . $path, $this->options($options));
        $status   = $response->getStatusCode();

        if (200 > $status || 300 <= $status) {
            // Projektakte says what is wrong as {"detail": "…"}; the token is
            // in a header and never in what is logged.
            $body   = json_decode($response->getContent(false), true);
            $detail = true === is_array($body) ? ($body['detail'] ?? null) : null;

            throw new \RuntimeException(sprintf('HTTP %d%s', $status, true === is_string($detail) ? ': ' . $detail : ''));
        }

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(array $options): array
    {
        return $options + [
            'auth_bearer'   => trim((string) $this->token),
            'headers'       => ['Accept' => 'application/json'],
            'timeout'       => self::TIMEOUT,
            'max_duration'  => self::MAX_DURATION,
            'max_redirects' => 0,
        ];
    }

    private function base(): ?string
    {
        $url = rtrim(trim((string) $this->url), '/');

        return '' !== $url ? $url : null;
    }

    /** Per address, so pointing the installation at another Projektakte shows its projects at once. */
    private function key(string $what): string
    {
        return sprintf('fork_projektakte_%s_%s', $what, hash('xxh128', (string) $this->base()));
    }

    private static function bodyOf(Message $message): string
    {
        $text = (string) $message->bodyText;

        if ('' === trim($text) && null !== $message->bodyHtml) {
            $text = html_entity_decode(strip_tags($message->bodyHtml), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }

        return $text;
    }

    /** @return list<string> */
    private static function attachmentNames(Message $message): array
    {
        $names = [];

        foreach ($message->messageParts as $part) {
            if (true === $part->isListed() && null !== $part->filename && '' !== $part->filename) {
                $names[] = $part->filename;
            }
        }

        return $names;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
