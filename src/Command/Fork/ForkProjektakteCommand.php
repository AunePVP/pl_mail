<?php

declare(strict_types=1);

namespace App\Command\Fork;

use App\Service\Fork\Projektakte\ForkProjektakteClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FORK (FORK.md 27): whether this installation reaches Projektakte with the
 * address and token it was given — for setting the integration up, where the
 * picker would only say "not reachable" and the reason is in the log.
 *
 * Reads only: info and the project list, nothing assigned, nothing notified.
 */
#[AsCommand(
    name: 'app:fork:projektakte',
    description: 'Check the connection to Projektakte (PROJEKTAKTE_URL, PROJEKTAKTE_TOKEN)',
)]
final class ForkProjektakteCommand extends Command
{
    public function __construct(
        private readonly ForkProjektakteClient $projektakte,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $result = $this->projektakte->info();

        if (false === $result['ok']) {
            $io->error(sprintf('Projektakte does not answer: %s', $result['error'] ?? 'no reason given'));

            return Command::FAILURE;
        }

        $info = $result['info'] ?? [];

        $io->success(sprintf(
            'Projektakte answers in %d ms: %s %s, %s projects, assign mode %s.',
            $result['ms'],
            self::text($info['name'] ?? '?'),
            self::text($info['version'] ?? '?'),
            self::text($info['projects'] ?? '?'),
            self::text($info['assign_mode'] ?? '?'),
        ));

        return Command::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return true === is_scalar($value) ? (string) $value : '?';
    }
}
