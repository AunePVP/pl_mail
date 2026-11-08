<?php

declare(strict_types=1);

namespace App\Tests\Support\Fork;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md 27): Projektakte's integration API as its contract describes
 * it (docs/plmail-integration.md over there), answered from a few made-up
 * projects, and every call kept for the test to look at.
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

    /** notify answers 500. */
    public bool $notifyFails = false;

    /** @var list<array{method: string, path: string, query: array<string, string>, body: array<mixed>, auth: ?string}> */
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

        $this->calls[] = [
            'method' => $method,
            'path'   => $path,
            'query'  => $query,
            'body'   => json_decode((string) ($options['body'] ?? '{}'), true) ?? [],
            'auth'   => $options['normalized_headers']['authorization'][0] ?? null,
        ];

        if (false === str_starts_with($url, $this->url . '/')) {
            throw new \LogicException(sprintf('Not Projektakte: %s', $url));
        }

        if (true === $this->down) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        return match ($path) {
            '/api/integration/info'     => self::json(['name' => 'Projektakte', 'version' => '0.3.0', 'assign_mode' => 'manual', 'projects' => count($this->projects)]),
            '/api/integration/projects' => self::json($this->list((string) ($query['q'] ?? ''))),
            '/api/integration/suggest'  => self::json(['suggestions' => $this->suggestions]),
            '/api/integration/notify'   => true === $this->notifyFails ? new MockResponse('{"detail":"boom"}', ['http_code' => 500]) : new MockResponse('{}', ['http_code' => 202]),
            default                     => new MockResponse('{"detail":"Not Found"}', ['http_code' => 404]),
        };
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
