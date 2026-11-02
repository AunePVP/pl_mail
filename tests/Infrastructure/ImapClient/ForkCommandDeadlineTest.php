<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapTimeoutException;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use PHPUnit\Framework\TestCase;

/**
 * FORK (FORK.md): the 240 s command deadline is for logged-in work, and only
 * for a caller that did not ask for less.
 *
 * It used to apply to every command, so a server that greeted and then hung
 * at CAPABILITY or LOGIN held a sync for four minutes, and the browser's
 * 10-second connection test ran into PHP's 30-second limit instead of saying
 * the server did not answer.
 *
 * Each server here goes quiet for three seconds where the client waits on it;
 * the client asked for one.
 */
final class ForkCommandDeadlineTest extends TestCase
{
    private ImapLoop $loop;

    /** @var list<ScriptedImapServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->loop = new ImapLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->finish();
        }
    }

    public function testCapabilityAfterTheGreetingKeepsTheCallersTimeout(): void
    {
        $server = $this->server(function (ScriptedImapPeer $peer): void {
            $peer->send('* OK ready');
            $this->loop->sleep(3.0);
            $peer->close();
        });

        $this->assertGivesUpInTime(fn () => ImapConnection::open($this->loop, $this->endpoint($server), 1.0));
    }

    public function testLoginKeepsTheCallersTimeout(): void
    {
        $server = $this->server(function (ScriptedImapPeer $peer): void {
            $peer->send('* OK [CAPABILITY IMAP4rev1] ready');
            $peer->line();
            $this->loop->sleep(3.0);
            $peer->close();
        });

        $connection = ImapConnection::open($this->loop, $this->endpoint($server), 1.0);

        $this->assertGivesUpInTime(fn () => $connection->authenticate(new ImapCredentials('user', 'secret')));
    }

    public function testAShorterTimeoutStillHoldsAfterLogin(): void
    {
        $server = $this->server(function (ScriptedImapPeer $peer): void {
            $peer->greetAndLogin();
            $peer->line();
            $this->loop->sleep(3.0);
            $peer->close();
        });

        $connection = ImapConnection::open($this->loop, $this->endpoint($server), 1.0);
        $connection->authenticate(new ImapCredentials('user', 'secret'));

        $this->assertGivesUpInTime(fn () => $connection->command('NOOP'));
    }

    private function assertGivesUpInTime(callable $call): void
    {
        $startedAt = microtime(true);

        try {
            $call();
            self::fail('the silent server was not given up on');
        } catch (ImapTimeoutException) {
        }

        self::assertLessThan(2.5, microtime(true) - $startedAt, 'waited past the one second the caller asked for');
    }

    private function server(callable $script): ScriptedImapServer
    {
        return $this->servers[] = new ScriptedImapServer($this->loop, $script);
    }

    private function endpoint(ScriptedImapServer $server): ImapEndpoint
    {
        return new ImapEndpoint('127.0.0.1', $server->port, ImapSecurity::None);
    }
}
