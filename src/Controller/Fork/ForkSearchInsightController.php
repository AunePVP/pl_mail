<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Entity\User\User;
use App\Service\Fork\Search\ForkSearchAnswer;
use App\Service\Fork\Search\ForkTripFinder;
use App\Service\Search\SearchQueryParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * FORK (FORK.md): the two panels above the search results, each a lazy
 * turbo-frame of its own (templates/fork/search/_insight.html.twig).
 *
 * Two frames rather than one, because they cost different amounts of time.
 * The coming events are a query and arrive with the page; the answer is the
 * chat model and takes seconds. In one frame the events would wait for it.
 *
 * Both re-read the query from the URL and run as the signed-in user, so
 * neither can show anything the results list could not.
 */
#[Route('/mail/search/fork', name: 'app_fork_search_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ForkSearchInsightController extends AbstractController
{
    public function __construct(
        private readonly SearchQueryParser $parser,
        private readonly ForkTripFinder    $trips,
        private readonly ForkSearchAnswer  $answers,
    ) {
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

    #[Route('/answer', name: 'answer', methods: ['GET'])]
    public function answer(Request $request, #[CurrentUser] User $user): Response
    {
        $this->releaseSession($request);

        $raw    = trim($request->query->getString('q'));
        $parsed = $this->parser->parse($raw);
        $answer = null;

        if ('' !== $parsed->freeText) {
            $trips  = $this->trips->find($user, $parsed->freeText);
            $answer = $this->answers->answer($user, $parsed, $raw, $trips);
        }

        return $this->render('fork/search/_answer.html.twig', ['answer' => $answer]);
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
