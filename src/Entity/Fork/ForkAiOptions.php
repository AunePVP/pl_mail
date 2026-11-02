<?php

declare(strict_types=1);

namespace App\Entity\Fork;

use App\Repository\Fork\ForkAiOptionsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * FORK (FORK.md): AI options this fork adds beside the original's ai_settings.
 *
 * A table of its own rather than a column on ai_settings, so an update from the
 * original can never meet a column or a migration of ours on its own table. One
 * row, held to one by the same singleton index LogSettings uses.
 */
#[ORM\Entity(repositoryClass: ForkAiOptionsRepository::class)]
#[ORM\Table(name: 'fork_ai_options')]
#[ORM\UniqueConstraint(name: 'uniq_fork_ai_options_singleton', columns: ['singleton'])]
class ForkAiOptions
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Always 1. Exists so the unique index above has a column to be unique over. */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * Whether the writing model may think before it answers.
     *
     * True is what the model does by itself, so true sends nothing and a server
     * that knows no switch for it is asked exactly as before. False asks it not
     * to — see ForkReasoning for how each dialect is told.
     */
    #[ORM\Column(name: 'reasoning_enabled', options: ['default' => true])]
    public bool $reasoningEnabled = true;

    /**
     * The full address of the search reranker's /rerank endpoint, or null for
     * none set here — then FORK_RERANK_URL decides (ForkReranker::url()).
     */
    #[ORM\Column(name: 'rerank_url', type: Types::TEXT, nullable: true)]
    public ?string $rerankUrl = null;
}
