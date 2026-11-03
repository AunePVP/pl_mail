<?php

declare(strict_types=1);

namespace App\Controller\Fork;

use App\Controller\ChecksCsrf;
use App\Domain\Helper\RequestTimeLimit;
use App\Entity\User\User;
use App\Service\Fork\Search\ForkSearchChat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FORK (FORK.md): one turn of the search chat (ForkSearchChat) as NDJSON, read
 * by assets/controllers/fork/search_chat_controller.js.
 *
 * A POST with the page's token, like the composer's assistant: the message is
 * somebody's question about their mail and has no business in a URL, a log
 * line or the browser's history.
 */
#[Route('/mail/search/fork/chat', name: 'app_fork_search_chat', methods: ['POST'])]
#[IsGranted('IS_AUTHENTICATED')]
final class ForkSearchChatController extends AbstractController
{
    use ChecksCsrf;

    /** As ForkSearchInsightController's: the model's idle timeout plus a cold load, twice over. */
    private const int TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly ForkSearchChat $chat,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertCsrf($request, 'ajax');

        // As SearchController does: the session lock would otherwise hold every
        // other request of this browser for as long as the model takes.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        $conversation = $request->request->getString('conversation');
        $message      = $request->request->getString('message');

        $response = new StreamedResponse(function () use ($user, $conversation, $message): void {
            $this->run($user, '' === $conversation ? null : $conversation, $message);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /** A method of its own so the generator is freed, and the model call cancelled, when it returns. */
    private function run(User $user, ?string $conversation, string $message): void
    {
        ignore_user_abort(true);
        RequestTimeLimit::hold(self::TIME_LIMIT_SECONDS);

        foreach ($this->chat->turn($user, $conversation, $message) as $frame) {
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
