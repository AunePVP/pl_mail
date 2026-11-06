<?php

declare(strict_types=1);

namespace App\Jmap\Method\Fork;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Mail\MessageRepository;
use App\Twig\Fork\ForkUnsubscribeExtension;

/**
 * FORK (FORK.md 22): `ForkUnsubscribe/get` — the web reader's Unsubscribe
 * button (FORK.md 13) for JMAP clients, so the iOS app can draw the same one.
 *
 * Arguments: `accountId`, `ids` (Email ids). Answer: `list` of
 * `{id, url}` (a web link), `{id, mailto}` (an address to write to) or nothing
 * for that id, and `notFound`. A link found in the mail's text rather than its
 * `List-Unsubscribe` header carries `fromBody: true`, so the client can say it
 * is a guess. The rules (spam and bin, drafts, replies) are exactly the web
 * reader's: both go through {@see ForkUnsubscribeExtension::target()}.
 *
 * A separate method rather than an Email property, so no file of the original
 * changes: the method registry picks up any tagged JmapMethod, and a client
 * on a server without it gets `unknownMethod` and simply shows no button.
 */
final class ForkUnsubscribeGetMethod implements JmapMethod
{
    private const int MAX_OBJECTS = 100;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly ForkUnsubscribeExtension $unsubscribe,
    ) {
    }

    public function name(): string
    {
        return 'ForkUnsubscribe/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $ids = $arguments['ids'] ?? null;

        if (false === is_array($ids)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($ids) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per ForkUnsubscribe/get.', self::MAX_OBJECTS));
        }

        $ids = array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
        $messages = $this->messageRepository->findByAccountAndIds($account->id, array_map('intval', $ids));

        $list = [];
        $found = [];

        foreach ($messages as $message) {
            $id = (string) $message->id;
            $found[] = $id;
            $target = $this->unsubscribe->target($message);

            if (null === $target) {
                continue;
            }

            $list[] = isset($target['to'])
                ? ['id' => $id, 'mailto' => $target['to']]
                : ['id' => $id, 'url' => $target['url'], 'fromBody' => isset($target['body'])];
        }

        return [
            'accountId' => (string) $account->id,
            'list' => $list,
            'notFound' => array_values(array_diff($ids, $found)),
        ];
    }
}
