<?php

declare(strict_types=1);

namespace App\Service\Fork;

use App\Domain\DTO\Mail\ThreadRowMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * FORK (FORK.md 29): a row in the Sent list is drawn from your own mail.
 *
 * Sent lists conversations, and a conversation is in it because one of its
 * messages carries Sent. Its row was drawn from the conversation's newest
 * message whatever that was, so a reply that came in afterwards put its
 * recipients ("To: you, …"), its text, its time and its unread state on the
 * row, and moved the row to the top of Sent. It read as received mail filed
 * under Sent. Gmail does the same; Outlook and Thunderbird list single
 * messages there, which is what people coming from them expect.
 *
 * The shape is BinView's: the list decides which side of the conversation its
 * row is drawn from (ThreadRows::side()). Opened, the conversation is shown
 * whole, replies included — this is about the row, not the reading pane.
 *
 * The Sent list is being looked at when
 *
 *  - the page is the Sent list (`app_mail_sent`), its pages and the list
 *    frame's refreshes included;
 *  - the page is one account's Sent folder, which the sidebar links as the
 *    label view of its Sent-role label (`app_mail_label`);
 *  - a row or toolbar action names either as the list it was pressed in
 *    (assets/list_view.js sends `scope`, and `value` for a label), so a row
 *    redrawn after a star or a mark-read is the same row.
 *
 * Anything else — the Inbox, a search hit, a worker — draws the whole
 * conversation as before.
 */
final readonly class ForkSentView
{
    private const string SENT_SCOPE = 'sent';

    private const string LABEL_SCOPE = 'label';

    public function __construct(
        private RequestStack $requests,
        private LabelRepository $labels,
        private Connection $connection,
    ) {
    }

    public function isOpen(): bool
    {
        $request = $this->requests->getMainRequest();

        if (null === $request) {
            return false;
        }

        $route = $request->attributes->get('_route');

        if ('app_mail_sent' === $route) {
            return true;
        }

        if ('app_mail_label' === $route) {
            return $this->isSentLabel($request->attributes->get('id'));
        }

        [$scope, $value] = self::scopeOf($request);

        return self::SENT_SCOPE === $scope
            || (self::LABEL_SCOPE === $scope && $this->isSentLabel($value));
    }

    /**
     * Which of these messages carry Sent — one statement for a page of rows,
     * and none at all outside the Sent list.
     *
     * By role rather than by `Message::isFiledAsSent()`, which counts Drafts
     * too: an unsent reply is not what this conversation was sent as.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    public function sentAmong(array $ids): array
    {
        if ([] === $ids || false === $this->isOpen()) {
            return [];
        }

        /** @var list<int|string> $found */
        $found = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT ml.message_id FROM message_label ml
                JOIN label l ON l.id = ml.label_id
                WHERE ml.message_id IN (:ids) AND l.role = :sent',
            ['ids' => $ids, 'sent' => LabelRole::Sent->value],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map(intval(...), $found), true);
    }

    /**
     * The same for messages already in memory (the thread view, a status
     * stream), read off their labels: a message not yet flushed has no row to
     * ask about.
     *
     * @param list<Message> $messages
     *
     * @return array<int, true>
     */
    public function sentOf(array $messages): array
    {
        if ([] === $messages || false === $this->isOpen()) {
            return [];
        }

        $sent = [];

        foreach ($messages as $message) {
            if (null === $message->id) {
                continue;
            }

            foreach ($message->labels as $label) {
                if (LabelRole::Sent === $label->role) {
                    $sent[$message->id] = true;
                    break;
                }
            }
        }

        return $sent;
    }

    /**
     * The messages a Sent row is drawn from: those carrying Sent, oldest first
     * as given. A conversation with none of them is drawn whole, as BinView
     * draws a side with nothing on it.
     *
     * @param list<ThreadRowMessage> $messages
     * @param array<int, true>       $sent     from sentAmong() or sentOf()
     *
     * @return list<ThreadRowMessage>
     */
    public static function keep(array $messages, array $sent): array
    {
        if ([] === $sent) {
            return $messages;
        }

        $kept = array_values(array_filter(
            $messages,
            static fn (ThreadRowMessage $message): bool => null !== $message->id && isset($sent[$message->id]),
        ));

        return [] === $kept ? $messages : $kept;
    }

    /** Whether a label id names a Sent folder. Checked as BinView::isTrashLabel() is. */
    private function isSentLabel(mixed $id): bool
    {
        if (false === is_numeric($id)) {
            return false;
        }

        return LabelRole::Sent === $this->labels->find((int) $id)?->role;
    }

    /**
     * The list a JSON status request was pressed in; BinView::scopeOf().
     *
     * @return array{?string, mixed}
     */
    private static function scopeOf(Request $request): array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return [null, null];
        }

        try {
            $body = $request->getPayload()->all();
        } catch (JsonException) {
            return [null, null];
        }

        $scope = $body['scope'] ?? null;

        return [is_string($scope) ? $scope : null, $body['value'] ?? null];
    }
}
