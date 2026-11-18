<?php

declare(strict_types=1);

namespace App\Tests\Support\Fork;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md 27, 30): Projektakte's integration API as its contract
 * describes it (docs/plmail-integration.md and docs/plmail-push.md over
 * there), answered from a few made-up projects, and every call kept for the
 * test to look at. Pushed mails are kept by "accountId/emailId", as
 * Projektakte keeps them.
 *
 * Each instance has an address of its own, because the project list is cached
 * by address in the app cache, which outlives a test.
 */
final class FakeProjektakte
{
    public const string TOKEN = 'integration-secret';

    public readonly string $url;

    /** Every connection refused. */
    public bool $down = false;

    /** The push endpoints answer 500 (Projektakte up, but failing). */
    public bool $pushFails = false;

    /** The push endpoints answer this instead (400, 401, 413 …). */
    public ?int $pushStatus = null;

    /** @var array<string, array{eml: string, assignment: array<mixed>}> what Projektakte holds, by "accountId/emailId" */
    public array $mails = [];

    /** @var list<array{method: string, path: string, query: array<string, string>, body: array<mixed>, raw: string, headers: array<string, list<string>>, auth: ?string}> */
    public array $calls = [];

    /** @var list<array{number: string, name: string, url: string}> the list, newest first */
    public array $projects = [
        ['number' => 'BS-0000-0002', 'name' => 'Schule Nord', 'url' => 'https://projektakte.example/projekte/BS-0000-0002'],
        ['number' => 'BS-0000-0001', 'name' => 'Rathaus Umbau', 'url' => 'https://projektakte.example/projekte/BS-0000-0001'],
    ];

    /** Found by asking for it, but not in the list (older than its first 500). */
    public array $older = ['number' => 'BS-0000-0099', 'name' => 'Altbau Lager', 'url' => 'https://projektakte.example/projekte/BS-0000-0099'];

    /** @var list<array<string, mixed>> */
    public array $suggestions = [
        ['number' => 'BS-0000-0001', 'name' => 'Rathaus Umbau', 'confidence' => 'sicher', 'score' => 1.0, 'reason' => 'Projektnummer BS-0000-0001 im Betreff', 'url' => 'https://projektakte.example/projekte/BS-0000-0001'],
        ['number' => 'BS-0000-0002', 'name' => 'Schule Nord', 'confidence' => 'niedrig', 'score' => 0.31, 'reason' => '31 % der ähnlichsten Abschnitte', 'url' => 'https://projektakte.example/projekte/BS-0000-0002'],
    ];

    public function __construct()
    {
        $this->url = sprintf('http://projektakte-%s.invalid', bin2hex(random_bytes(4)));
    }

    /** As the app sees it: PROJEKTAKTE_URL and PROJEKTAKTE_TOKEN, or neither. */
    public static function configure(?self $fake): void
    {
        foreach (['PROJEKTAKTE_URL' => $fake?->url, 'PROJEKTAKTE_TOKEN' => null === $fake ? null : self::TOKEN] as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);

                continue;
            }

            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->answer(...));
    }

    /** @return list<string> the paths asked for, in order */
    public function paths(): array
    {
        return array_column($this->calls, 'path');
    }

    /** @param array<string, mixed> $options */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $raw = self::read($options['body'] ?? '');

        $this->calls[] = [
            'method'  => $method,
            'path'    => $path,
            'query'   => $query,
            'body'    => json_decode('' !== $raw ? $raw : '{}', true) ?? [],
            'raw'     => $raw,
            'headers' => $options['normalized_headers'] ?? [],
            'auth'    => $options['normalized_headers']['authorization'][0] ?? null,
        ];

        if (false === str_starts_with($url, $this->url . '/')) {
            throw new \LogicException(sprintf('Not Projektakte: %s', $url));
        }

        if (true === $this->down) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        if (1 === preg_match('~^/api/integration/mails/([\w.-]+)/([\w.-]+)(/zuordnung)?$~', $path, $m)) {
            return $this->mail($method, $m[1] . '/' . $m[2], isset($m[3]), $raw, $options);
        }

        return match ($path) {
            '/api/integration/info'     => self::json(['name' => 'Projektakte', 'version' => '0.3.0', 'assign_mode' => 'manual', 'projects' => count($this->projects)]),
            '/api/integration/projects' => self::json($this->list((string) ($query['q'] ?? ''))),
            '/api/integration/suggest'  => self::json(['suggestions' => $this->suggestions]),
            default                     => new MockResponse('{"detail":"Not Found"}', ['http_code' => 404]),
        };
    }

    /**
     * The push endpoints, as Projektakte answers them.
     *
     * @param array<string, mixed> $options
     */
    private function mail(string $method, string $key, bool $assignmentOnly, string $raw, array $options): MockResponse
    {
        if (true === $this->pushFails) {
            return new MockResponse('{"detail":"boom"}', ['http_code' => 500]);
        }

        if (null !== $this->pushStatus) {
            return new MockResponse('{"detail":"refused"}', ['http_code' => $this->pushStatus]);
        }

        if ('DELETE' === $method) {
            $had = isset($this->mails[$key]);
            unset($this->mails[$key]);

            return self::json(['status' => true === $had ? 'gelöscht' : 'nicht vorhanden']);
        }

        if ('GET' === $method) {
            return self::json(['vorhanden' => isset($this->mails[$key]), 'wartet' => false, 'zuordnung' => $this->mails[$key]['assignment'] ?? null]);
        }

        if (true === $assignmentOnly) {
            if (false === isset($this->mails[$key])) {
                return new MockResponse('{"detail":"Mail nicht vorhanden"}', ['http_code' => 404]);
            }

            $this->mails[$key]['assignment'] = json_decode($raw, true);

            return self::json(['status' => 'geändert']);
        }

        // Normalised headers come as "Name: value".
        $line       = (string) ($options['normalized_headers']['x-projektakte-zuordnung'][0] ?? '');
        $assignment = json_decode(trim(substr($line, (int) strpos($line, ':') + 1)), true);

        if (false === is_array($assignment) || 'assigned' !== ($assignment['status'] ?? null)) {
            return new MockResponse('{"detail":"nur zugeordnete Mails"}', ['http_code' => 422]);
        }

        $this->mails[$key] = ['eml' => $raw, 'assignment' => $assignment];

        return new MockResponse('{"status":"angenommen"}', ['http_code' => 202]);
    }

    /** A body as the mock client hands it over: a string, a stream, or a closure. */
    private static function read(mixed $body): string
    {
        if (true === is_string($body)) {
            return $body;
        }

        if (true === is_resource($body)) {
            return (string) stream_get_contents($body, -1, 0);
        }

        if ($body instanceof \Closure) {
            $raw = '';

            while ('' !== ($chunk = $body(16384))) {
                $raw .= $chunk;
            }

            return $raw;
        }

        return '';
    }

    /** @return array{count: int, projects: list<array<string, string>>} */
    private function list(string $q): array
    {
        $projects = '' === $q ? $this->projects : array_values(array_filter(
            [...$this->projects, $this->older],
            static fn (array $project): bool => false !== stripos($project['number'] . ' ' . $project['name'], $q),
        ));

        return ['count' => count($projects), 'projects' => $projects];
    }

    /** @param array<mixed> $data */
    private static function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['response_headers' => ['Content-Type: application/json']]);
    }
}
