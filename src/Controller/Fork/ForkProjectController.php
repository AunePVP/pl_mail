<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Fork\Projektakte\ForkProjectAssigner;
use App\Service\Fork\Projektakte\ForkProjectRefused;
use App\Service\Fork\Projektakte\ForkProjektakteClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * FORK (FORK.md 27): the project chip in a message's header.
 *
 *   GET    the picker: Projektakte's suggestions and its project list, asked
 *          for here — the token never reaches the browser
 *   POST   {"number": "…"} files the message under that project,
 *          {"none": true} says it belongs to none; answers with the chip
 *   DELETE back to undecided; answers with the chip
 *
 * Not there at all (404) unless Projektakte is configured. A Projektakte that
 * is down is a sentence in the picker, never a 500: the chip and the two
 * answers that need no project list keep working.
 */
#[Route('/mail/message/{id}/fork/project', name: 'app_fork_project', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class ForkProjectController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly ForkProjektakteClient        $projektakte,
        private readonly ForkProjectAssigner          $assigner,
        private readonly ForkMessageProjectRepository $rows,
        private readonly TranslatorInterface          $translator,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function picker(Message $message, Request $request): Response
    {
        $this->guard($message);

        // Asking Projektakte for suggestions can take seconds; the session lock
        // would hold every other request of this browser for as long.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        $current     = $this->rows->forMessage($message);
        $projects    = $this->projektakte->projects();
        $suggestions = null !== $projects ? ($this->projektakte->suggest($message) ?? []) : [];

        // "Open in Projektakte" for the project it is filed under, from the list.
        $currentUrl = null !== $current?->projectNumber ? ($projects[$current->projectNumber] ?? null)?->url : null;

        return $this->render('fork/project/_picker.html.twig', [
            'message'     => $message,
            'current'     => $current,
            'currentUrl'  => $currentUrl,
            'available'   => null !== $projects,
            'projects'    => array_values($projects ?? []),
            'suggestions' => $suggestions,
        ]);
    }

    #[Route('', name: '_assign', methods: ['POST'])]
    public function assign(Message $message, Request $request, #[CurrentUser] User $user): Response
    {
        $this->guard($message);
        $this->assertCsrf($request, 'ajax');

        $body = json_decode($request->getContent(), true);
        $body = true === is_array($body) ? $body : [];

        if (true === ($body['none'] ?? false)) {
            return $this->chip($message, $this->assigner->markNone($message, $user));
        }

        $number = $body['number'] ?? null;

        if (false === is_string($number) || '' === trim($number)) {
            return $this->refused('unknown', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $row = $this->assigner->assign($message, $user, $number);
        } catch (ForkProjectRefused $refused) {
            return $this->refused(
                $refused->reason,
                ForkProjectRefused::UNAVAILABLE === $refused->reason ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->chip($message, $row);
    }

    #[Route('', name: '_clear', methods: ['DELETE'])]
    public function clear(Message $message, Request $request): Response
    {
        $this->guard($message);
        $this->assertCsrf($request, 'ajax');

        $this->assigner->clear($message);

        return $this->chip($message, null);
    }

    private function guard(Message $message): void
    {
        if (false === $this->projektakte->isAvailable()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
    }

    private function chip(Message $message, ?ForkMessageProject $assignment): Response
    {
        return $this->renderTurboStream('fork/project/_assigned.stream.html.twig', [
            'message'    => $message,
            'assignment' => $assignment,
        ]);
    }

    /** The picker shows the sentence where the list is; nothing was changed. */
    private function refused(string $reason, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans('message.fork_project.' . $reason, [], 'fork')], $status);
    }
}
