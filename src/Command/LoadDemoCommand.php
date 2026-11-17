<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoData;
use App\Demo\DemoDataException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Loads the demo Speeches from demo/, already processed, without any API call (SPEC §11).
 * Running it again skips Speeches that are already there.
 */
#[AsCommand('app:load-demo', 'Load the processed demo Speeches from demo/ (no API keys needed)')]
final readonly class LoadDemoCommand
{
    public function __construct(
        private DemoData $demoData,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $loads = $this->demoData->load();
        } catch (DemoDataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($loads as $load) {
            $rows[] = [
                $load->speech->getDisplayTitle(),
                $load->created ? 'loaded' : 'already loaded',
                $this->urls->generate('speech_show', ['id' => $load->speech->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }
        $io->table(['Speech', 'Status', 'URL'], $rows);
        $io->success(\sprintf('%d demo Speech(es) ready.', \count($loads)));

        return Command::SUCCESS;
    }
}
