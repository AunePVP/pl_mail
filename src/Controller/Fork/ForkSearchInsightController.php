<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Domain\Helper\RequestTimeLimit;
use App\Entity\User\User;
use App\Service\Fork\Search\ForkAnswerRequest;
use App\Service\Fork\Search\ForkReranker;
use App\Service\Fork\Search\ForkSearchAnswer;
use App\Service\Fork\Search\ForkSearchCandidates;
use App\Service\Fork\Search\ForkTripFinder;
use App\Service\Search\SearchQueryParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * FORK (FORK.md): the panels above the search results, each a lazy
 * turbo-frame of its own (templates/fork/search/_insight.html.twig).
 *
 * Separate frames rather than one, because they cost different amounts of
 * time. The coming events are a query and arrive with the page; the best
 * results are a small reranker and take about a second; the answer is the
 * chat model and takes several. In one frame each would wait for the slowest.
 *
 * All re-read the query from the URL and run as the signed-in user, so
 * none can show anything the results list could not.
 */
#[Route('/mail/search/fork', name: 'app_fork_search_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ForkSearchInsightController extends AbstractController
{
    /**
     * Qwen3-Reranker's score is a probability of "yes, this matches": a real
     * match sits near 0.9, a near miss near 0.05. Half is the line between.
     */
    private const float BEST_SCORE = 0.5;

    private const int BEST_COUNT = 3;

    /** As ComposeAssistController's: above the model client's idle timeout plus a cold load. */
    private const int STREAM_TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly SearchQueryParser $parser,
        private readonly ForkTripFinder    $trips,
        private readonly ForkSearchAnswer  $answers,
        private readonly ForkReranker      $reranker,
        private readonly ForkSearchCandidates $candidates,
    ) {
    }

    /**
     * The results the reranker rates as clearly what was asked for — the
     * mail somebody was looking for, before the chat model has read anything.
     * Nothing at all without a reranker: the search's own first rows are
     * already the first rows of the list underneath.
     */
    #[Route('/best', name: 'best', methods: ['GET'])]
    public function best(Request $request, #[CurrentUser] User $user): Response
    {
        $this->releaseSession($request);

        $raw    = trim($request->query->getString('q'));
        $parsed = $this->parser->parse($raw);
        $best   = [];

        if ('' !== $parsed->freeText && true === $this->reranker->isAvailable()) {
            foreach ($this->candidates->for($user, $parsed, $raw) as $candidate) {
                // Unranked is the tail; the ranked part is ordered with age
                // weighed in, so a weak one can sit before a strong one.
                if (null === $candidate->score || self::BEST_COUNT <= count($best)) {
                    break;
                }

                if (self::BEST_SCORE <= $candidate->score) {
                    $best[] = $candidate;
                }
            }
        }

        return $this->render('fork/search/_best.html.twig', ['best' => $best]);
    }

    #[Route('/trips', name: 'trips', methods: ['GET'])]
    public function trips(Request $request, #[CurrentUser] User $user): Response
    {
        $this->releaseSession($request);

        $parsed = $this->parser->parse(trim($request->query->getString('q')));

        return $this->render('fork/search/_trips.html.twig', [
            'trips' => '' === $parsed->freeText ? [] : $this->trips->find($user, $parsed->freeText),
        ]);
    }

    /**
     * The answer's frame. An answer already given is drawn whole; otherwise
     * the frame is a box that asks answerStream() and fills while the model
     * writes, then loads this again with `after=1` for the finished answer.
     * `after` never starts a stream, so a cache that lost the answer is an
     * empty frame and not a loop.
     */
    #[Route('/answer', name: 'answer', methods: ['GET'])]
    public function answer(Request $request, #[CurrentUser] User $user): Response
    {
        $this->releaseSession($request);

        $raw      = trim($request->query->getString('q'));
        $question = $this->question($user, $raw);
        $answer   = null === $question ? null : $this->answers->cached($question);

        return $this->render('fork/search/_answer.html.twig', [
            'answer' => false === $answer ? null : $answer,
            'stream' => false === $answer && false === $request->query->getBoolean('after'),
            'q'      => $raw,
        ]);
    }

    /**
     * The answer as NDJSON while the model writes it, as the composer's
     * assistant streams (ComposeAssistController::assistStream, whose comments
     * say why each line below is there): `token` frames with the prose, then
     * `done`, or `error`.
     */
    #[Route('/answer/stream', name: 'answer_stream', methods: ['GET'])]
    public function answerStream(Request $request, #[CurrentUser] User $user): Response
    {
        $this->releaseSession($request);

        $question = $this->question($user, trim($request->query->getString('q')));

        $response = new StreamedResponse(function () use ($question): void {
            $this->generate($question);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function question(User $user, string $raw): ?ForkAnswerRequest
    {
        $parsed = $this->parser->parse($raw);

        if ('' === $parsed->freeText) {
            return null;
        }

        return $this->answers->prepare($user, $parsed, $raw, $this->trips->find($user, $parsed->freeText));
    }

    /** A method of its own so the generator is freed, and the model call cancelled, when it returns. */
    private function generate(?ForkAnswerRequest $question): void
    {
        ignore_user_abort(true);
        RequestTimeLimit::hold(self::STREAM_TIME_LIMIT_SECONDS);

        if (null === $question) {
            $this->frame(['type' => 'done']);

            return;
        }

        if (false !== $this->answers->cached($question)) {
            $this->frame(['type' => 'done']);

            return;
        }

        $tokens = $this->answers->stream($question);

        if (null === $tokens) {
            $this->frame(['type' => 'error']);

            return;
        }

        foreach ($tokens as $text) {
            $this->frame(['type' => 'token', 'text' => $text]);

            if (0 !== connection_aborted()) {
                break;
            }
        }

        if (true === $tokens->valid()) {
            return;
        }

        $this->frame(null === $tokens->getReturn() && false === $this->answers->cached($question)
            ? ['type' => 'error']
            : ['type' => 'done']);
    }

    /**
     * One NDJSON frame, on the wire now: ob_flush() for php.ini's
     * output_buffering, then flush() (ComposeAssistController::frame()).
     *
     * @param array<string, mixed> $frame
     */
    private function frame(array $frame): void
    {
        echo json_encode($frame, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        if (0 < ob_get_level()) {
            ob_flush();
        }

        flush();
    }

    /**
     * As SearchController does: the session lock would otherwise hold every
     * other request of this browser for as long as the model takes.
     */
    private function releaseSession(Request $request): void
    {
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }
    }
}
