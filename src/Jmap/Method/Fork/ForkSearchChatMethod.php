<?php

declare(strict_types=1);

namespace App\Jmap\Method\Fork;

use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Fork\Search\ForkSearchChat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FORK (FORK.md 22): `ForkSearch/chat` — one turn of the web's AI search chat
 * (FORK.md 20) for JMAP clients.
 *
 * Arguments: `message` (what was typed), `conversation` (the id a previous
 * turn returned, or null to start one). The turn runs to its end — planning,
 * searching, ranking, the model's answer — and comes back whole, because JMAP
 * does not stream; that takes some seconds, the client shows that it is busy.
 *
 * Answer: `{conversation, queries, answer, important, results, error}` with
 * `important` and `results` as lists of ThreadRef
 * (`{threadId, accountId, emailId, subject, receivedAt}`, see
 * ForkSearchInsightMethod). `error: true` with `answer: null` when the model
 * is off or failed; the conversation id is kept either way so a client can
 * simply ask again.
 */
final class ForkSearchChatMethod implements JmapMethod
{
    public function __construct(
        private readonly ForkSearchChat $chat,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'ForkSearch/chat';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $message = $arguments['message'] ?? null;
        $conversation = $arguments['conversation'] ?? null;

        if (false === is_string($message) || '' === trim($message)) {
            throw new MethodException('invalidArguments', '"message" must be a non-empty string.');
        }

        if (null !== $conversation && false === is_string($conversation)) {
            throw new MethodException('invalidArguments', '"conversation" must be a string or null.');
        }

        $user = $context->user;
        $result = ['conversation' => null, 'queries' => [], 'answer' => null, 'important' => [], 'results' => [], 'error' => false];

        foreach ($this->chat->turn($user, $conversation, $message) as $event) {
            switch ($event['type'] ?? null) {
                case 'conversation':
                    $result['conversation'] = $event['id'];
                    break;
                case 'search':
                    $result['queries'] = array_values($event['queries'] ?? []);
                    break;
                case 'results':
                    $result['results'] = $this->refs($user, $event['items'] ?? []);
                    break;
                case 'done':
                    $result['answer'] = $event['answer'];
                    $result['important'] = $this->refs($user, $event['important'] ?? []);
                    break;
                case 'error':
                    $result['error'] = true;
                    break;
            }
        }

        return $result;
    }

    /**
     * @param iterable<array{id: int|string}> $items
     *
     * @return list<array{threadId: string, accountId: string, emailId: ?string, subject: ?string, receivedAt: ?string}>
     */
    private function refs(User $user, iterable $items): array
    {
        $refs = [];

        foreach ($items as $item) {
            $thread = $this->em->find(MessageThread::class, (int) $item['id']);

            // The ids come from this user's own search, but a thread is only
            // ever described to the user it belongs to.
            if (null === $thread || null === $thread->account || $thread->account->usr !== $user) {
                continue;
            }

            $newest = null;

            foreach ($thread->messages as $candidate) {
                if (null === $newest || $candidate->receivedAt > $newest->receivedAt) {
                    $newest = $candidate;
                }
            }

            $refs[] = [
                'threadId' => (string) $thread->id,
                'accountId' => (string) $thread->account->id,
                'emailId' => null === $newest ? null : (string) $newest->id,
                'subject' => $thread->subject,
                'receivedAt' => $thread->lastMessageAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $refs;
    }
}
