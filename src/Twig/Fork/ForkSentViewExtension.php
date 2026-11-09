<?php

declare(strict_types=1);

namespace App\Twig\Fork;

use App\Service\Fork\ForkSentView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FORK (FORK.md 29): `fork_sent_view()` — whether this request draws rows for
 * the Sent list, as `bin_view()` says it for the bin.
 *
 * The row template's `sent_scope` (the "To: …" column) was passed by the Sent
 * list alone, so a row redrawn after a star or a mark-read, and every row of
 * one account's Sent folder, named the conversation's participants instead.
 * The template now falls back to this when nobody passed it.
 */
final class ForkSentViewExtension extends AbstractExtension
{
    public function __construct(
        private readonly ForkSentView $sent,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fork_sent_view', $this->sent->isOpen(...)),
        ];
    }
}
