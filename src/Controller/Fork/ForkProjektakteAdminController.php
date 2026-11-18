<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Controller\ChecksCsrf;
use App\Service\Fork\Projektakte\ForkProjektakteClient;
use App\Service\Fork\Projektakte\ForkProjektakteCourier;
use App\Service\Fork\Projektakte\ForkProjektakteOutboxStore;
use App\Service\Fork\Projektakte\ForkProjektaktePush;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FORK (FORK.md 30): the Projektakte section under Admin → Integrations.
 *
 *   GET   whether Projektakte answers, what is waiting in the outbox, the
 *         last error, and how far "send all filed mail" has got — a frame of
 *         its own, loaded lazily (asking Projektakte can take five seconds)
 *         and reloading itself while something is waiting
 *   POST  …/resend: every filed mail into the outbox, once each
 *
 * 404 unless Projektakte is configured, like the chip.
 */
#[Route('/admin/fork/projektakte', name: 'app_fork_projektakte_admin')]
#[IsGranted('ROLE_ADMIN')]
final class ForkProjektakteAdminController extends AbstractController
{
    use ChecksCsrf;

    public const string CSRF = 'fork_projektakte_resend';

    public function __construct(
        private readonly ForkProjektakteClient      $projektakte,
        private readonly ForkProjektaktePush        $push,
        private readonly ForkProjektakteCourier     $courier,
        private readonly ForkProjektakteOutboxStore $outbox,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function status(Request $request): Response
    {
        $this->guard();

        // Asking Projektakte may take its full timeout; nothing else of this
        // browser should wait behind the session lock for that.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        $waiting = $this->push->waiting();
        $resend  = $this->push->lastResend();

        return $this->render('fork/projektakte/_admin.html.twig', [
            'reachable' => $this->projektakte->reachable(),
            'waiting'   => $waiting,
            'failing'   => $this->outbox->newestError(),
            'lastError' => $this->courier->lastError(),
            'resend'    => $resend,
            'done'      => null !== $resend ? max(0, $resend['total'] - $waiting) : 0,
        ]);
    }

    #[Route('/resend', name: '_resend', methods: ['POST'])]
    public function resend(Request $request): Response
    {
        $this->guard();
        $this->assertCsrf($request, self::CSRF);

        $this->push->resendAll();

        return $this->redirectToRoute('app_fork_projektakte_admin', [], Response::HTTP_SEE_OTHER);
    }

    private function guard(): void
    {
        if (false === $this->projektakte->isAvailable()) {
            throw $this->createNotFoundException();
        }
    }
}
