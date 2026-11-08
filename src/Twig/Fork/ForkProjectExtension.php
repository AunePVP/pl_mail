<?php

declare(strict_types=1);

namespace App\Twig\Fork;

use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Service\Fork\Projektakte\ForkProjektakteClient;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FORK (FORK.md 27): what the project chip in a message's header reads.
 *
 *   fork_projektakte()       whether the integration is configured; without
 *                            it there is no chip and no query
 *   fork_project(message)    the message's assignment, or null
 *
 * A conversation draws one chip per message, so the first message asked for
 * loads the whole conversation's assignments in one query (the way
 * InviteReader reads invitations), and the rest are answered from that.
 * Reset between requests: a worker serves many, and an assignment changed in
 * one must not be drawn as it was in the next.
 */
final class ForkProjectExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, array<int, ForkMessageProject>> by thread id, then message id */
    private array $byThread = [];

    public function __construct(
        private readonly ForkProjektakteClient        $projektakte,
        private readonly ForkMessageProjectRepository $rows,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fork_projektakte', $this->projektakte->isAvailable(...)),
            new TwigFunction('fork_project', $this->project(...)),
        ];
    }

    public function project(Message $message): ?ForkMessageProject
    {
        if (null === $message->id || false === $this->projektakte->isAvailable()) {
            return null;
        }

        $thread = $message->thread;

        if (null === $thread || null === $thread->id) {
            return $this->rows->findByMessageIds([$message->id])[$message->id] ?? null;
        }

        $this->byThread[$thread->id] ??= $this->rows->findByThread($thread);

        return $this->byThread[$thread->id][$message->id] ?? null;
    }

    public function reset(): void
    {
        $this->byThread = [];
    }
}
