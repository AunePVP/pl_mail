<?php

declare(strict_types=1);

namespace App\Jmap\Method\Fork;

use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Mail\MessageRepository;
use App\Entity\User\User;
use App\Service\Fork\Search\ForkAnswer;
use App\Service\Fork\Search\ForkReranker;
use App\Service\Fork\Search\ForkSearchAnswer;
use App\Service\Fork\Search\ForkSearchCandidates;
use App\Service\Fork\Search\ForkTrip;
use App\Service\Fork\Search\ForkTripFinder;
use App\Domain\DTO\ParsedSearchQuery;
use App\Service\Search\SearchQueryParser;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * FORK (FORK.md 22): `ForkSearch/insight` — the three panels the web search
 * draws above its results (FORK.md 16 and 18) for JMAP clients.
 *
 * Arguments: `query` (the search text as typed, operators included) and
 * `parts`, a subset of `["best", "trips", "answer"]` (default all). Each part
 * costs very differently — trips are a query, best is the reranker (~1–5 s),
 * the answer is the chat model (~5–10 s) — so a client asks for them in
 * separate calls and draws each as it arrives, like the web's lazy frames.
 *
 * Answer, all keys present (a part not asked for is null):
 *   best:   list<ThreadRef>               up to 3 reranked hits, score ≥ 0.5
 *   trips:  list<Trip>                    upcoming events the query names
 *   answer: {text, important: list<ThreadRef>, groups: list<{title, threads}>}|null
 *
 * ThreadRef = {threadId, accountId, emailId, subject, receivedAt}: emailId is
 * the thread's newest message, so a client can open it without another lookup.
 * Trip = {title, location, start, end, isAllDay, timeZone, isOffer, emails}.
 *
 * User-wide like the web search, so `accountId` is not required. A part whose
 * service is off (no reranker, AI summaries disabled) comes back empty or null,
 * never as an error: the panel then simply isn't drawn.
 */
final class ForkSearchInsightMethod implements JmapMethod
{
    private const float BEST_SCORE = 0.5;

    private const int BEST_COUNT = 3;

    private const array PARTS = ['best', 'trips', 'answer'];

    public function __construct(
        private readonly SearchQueryParser $parser,
        private readonly ForkTripFinder $trips,
        private readonly ForkSearchAnswer $answers,
        private readonly ForkReranker $reranker,
        private readonly ForkSearchCandidates $candidates,
        private readonly MessageRepository $messages,
    ) {
    }

    public function name(): string
    {
        return 'ForkSearch/insight';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $raw = $arguments['query'] ?? null;

        if (false === is_string($raw)) {
            throw new MethodException('invalidArguments', '"query" must be a string.');
        }

        $parts = $arguments['parts'] ?? self::PARTS;

        if (false === is_array($parts) || [] !== array_diff($parts, self::PARTS)) {
            throw new MethodException('invalidArguments', '"parts" must be a subset of ["best", "trips", "answer"].');
        }

        $user = $context->user;
        $raw = trim($raw);
        $parsed = $this->parser->parse($raw);
        $result = ['best' => null, 'trips' => null, 'answer' => null];

        if ('' === $parsed->freeText) {
            return $result;
        }

        $trips = null;

        if (true === in_array('trips', $parts, true) || true === in_array('answer', $parts, true)) {
            $trips = $this->trips->find($user, $parsed->freeText);
        }

        if (true === in_array('trips', $parts, true)) {
            $result['trips'] = array_map($this->trip(...), $trips ?? []);
        }

        if (true === in_array('best', $parts, true)) {
            $best = [];

            if (true === $this->reranker->isAvailable()) {
                foreach ($this->candidates->for($user, $parsed, $raw) as $candidate) {
                    // Same rule as the web's "best" panel: the ranked part
                    // comes first, the unranked tail ends it.
                    if (null === $candidate->score || self::BEST_COUNT <= count($best)) {
                        break;
                    }

                    if (self::BEST_SCORE <= $candidate->score) {
                        $best[] = $this->threadRef($candidate->thread);
                    }
                }
            }

            $result['best'] = $best;
        }

        if (true === in_array('answer', $parts, true)) {
            $answer = $this->answer($user, $parsed, $raw, $trips ?? []);

            if (null !== $answer) {
                $result['answer'] = [
                    'text' => $answer->text,
                    'important' => array_map($this->threadRef(...), $answer->important),
                    'groups' => array_map(fn (array $group): array => [
                        'title' => $group['title'],
                        'threads' => array_map($this->threadRef(...), $group['threads']),
                    ], $answer->groups),
                ];
            }
        }

        return $result;
    }

    /**
     * The web's answer, whole: from the cache when the web or an earlier call
     * already asked, otherwise the model's stream run to its end (JMAP has no
     * streaming; the client shows a spinner meanwhile). Cached by the service
     * the same way, so the web and the app share one answer per query.
     *
     * @param list<ForkTrip> $trips
     */
    private function answer(User $user, ParsedSearchQuery $parsed, string $raw, array $trips): ?ForkAnswer
    {
        $request = $this->answers->prepare($user, $parsed, $raw, $trips);

        if (null === $request) {
            return null;
        }

        $cached = $this->answers->cached($request);

        if (false !== $cached) {
            return $cached;
        }

        $tokens = $this->answers->stream($request);

        if (null === $tokens) {
            return null;
        }

        foreach ($tokens as $ignored) {
            // Drained for its return value; the prose arrives whole below.
        }

        return $tokens->getReturn();
    }

    /**
     * @return array{threadId: string, accountId: ?string, emailId: ?string, subject: ?string, receivedAt: ?string}
     */
    private function threadRef(MessageThread $thread): array
    {
        $newest = null;

        foreach ($thread->messages as $message) {
            if (null === $newest || ($message->receivedAt ?? null) > ($newest->receivedAt ?? null)) {
                $newest = $message;
            }
        }

        return [
            'threadId' => (string) $thread->id,
            'accountId' => null === $thread->account ? null : (string) $thread->account->id,
            'emailId' => null === $newest ? null : (string) $newest->id,
            'subject' => $thread->subject,
            'receivedAt' => self::utc($thread->lastMessageAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trip(ForkTrip $trip): array
    {
        $event = $trip->event;
        $emails = [];

        foreach ($trip->mail as $mail) {
            $message = $this->messages->find($mail['id']);

            if (false === $message instanceof Message) {
                continue;
            }

            $emails[] = [
                'emailId' => (string) $mail['id'],
                'threadId' => null === $mail['thread'] ? null : (string) $mail['thread'],
                'accountId' => (string) $message->account->id,
                'subject' => $mail['subject'],
                'from' => $mail['from'],
                'receivedAt' => self::utc($mail['receivedAt']),
            ];
        }

        return [
            'title' => $event->title,
            'location' => $event->location,
            'start' => null === $event->startsAt ? null : $event->startsAt->format(DateTimeInterface::ATOM),
            'end' => null === $event->endsAt ? null : $event->endsAt->format(DateTimeInterface::ATOM),
            'isAllDay' => $event->isAllDay,
            'timeZone' => $event->timeZone,
            'isOffer' => $trip->isOffer,
            'emails' => $emails,
        ];
    }

    private static function utc(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
