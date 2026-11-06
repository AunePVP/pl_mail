<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Controller\ChecksCsrf;
use App\Domain\Helper\RequestTimeLimit;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Fork\Attachment\ForkAttachmentChat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FORK (FORK.md): "Anhänge fragen" — the dialog (GET, into the modal frame)
 * and one turn of its chat (POST, NDJSON, read by
 * assets/controllers/fork/attachment_ask_controller.js).
 *
 * A POST with the page's token, like the search chat: the question is about
 * somebody's documents and has no business in a URL or a log line.
 */
#[Route('/mail/message/{id}/fork/ask', name: 'app_fork_attachment_ask', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class ForkAttachmentAskController extends AbstractController
{
    use ChecksCsrf;

    /** A long PDF is a long read before the first word, plus the answer. */
    private const int TIME_LIMIT_SECONDS = 600;

    public function __construct(
        private readonly ForkAttachmentChat $chat,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function dialog(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('fork/attachment/_ask.html.twig', [
            'message'   => $message,
            'parts'     => $this->chat->partsOf($message),
            'available' => $this->chat->isAvailable(),
        ]);
    }

    #[Route('', name: '_turn', methods: ['POST'])]
    public function turn(Message $message, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'ajax');

        // As SearchController does: the session lock would otherwise hold every
        // other request of this browser for as long as the model takes.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        $conversation = $request->request->getString('conversation');
        $question     = $request->request->getString('message');
        $parts        = array_values(array_filter(
            array_map('intval', explode(',', $request->request->getString('parts'))),
            static fn (int $id): bool => 0 < $id,
        ));

        $response = new StreamedResponse(function () use ($user, $message, $conversation, $question, $parts): void {
            $this->run($user, $message, '' === $conversation ? null : $conversation, $question, $parts);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * A method of its own so the generator is freed, and the model call cancelled, when it returns.
     *
     * @param list<int> $parts
     */
    private function run(User $user, Message $message, ?string $conversation, string $question, array $parts): void
    {
        ignore_user_abort(true);
        RequestTimeLimit::hold(self::TIME_LIMIT_SECONDS);

        foreach ($this->chat->turn($user, $message, $conversation, $question, $parts) as $frame) {
            $this->frame($frame);

            if (0 !== connection_aborted()) {
                return;
            }
        }
    }

    /**
     * One NDJSON frame, on the wire now (ComposeAssistController::frame()).
     *
     * @param array<string, mixed> $frame
     */
    private function frame(array $frame): void
    {
        echo json_encode($frame, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        if (0 < ob_get_level()) {
            ob_flush();
        }

        flush();
    }
}
