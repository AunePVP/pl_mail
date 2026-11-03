<?php

declare(strict_types=1);

namespace App\Twig\Fork;

use App\Service\Fork\Search\ForkSearchChat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FORK (FORK.md): `fork_search_chat()` — whether the "KI-Suche" button and the
 * search chat are offered. Behind the Summary switch, as the answer above the
 * search results is: both read the results instead of the mail.
 */
final class ForkSearchChatExtension extends AbstractExtension
{
    public function __construct(
        private readonly ForkSearchChat $chat,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fork_search_chat', $this->chat->isAvailable(...)),
        ];
    }
}
