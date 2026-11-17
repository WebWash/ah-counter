<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoData;
use App\Demo\DemoDataException;
use App\Repository\SpeechRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Saves a processed Speech as demo data: demo/{name}.json plus its Recording (SPEC §11).
 */
#[AsCommand('app:export-demo', 'Save a done Speech, with its Recording and fixed ID, as demo data in demo/')]
final readonly class ExportDemoCommand
{
    public function __construct(
        private SpeechRepository $speeches,
        private DemoData $demoData,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The Speech ID (from its URL, /speeches/{id})')]
        string $id,
        #[Argument('The demo name, used for the file names, e.g. week-1')]
        string $name,
        #[Option('Replace demo files with the same name')]
        bool $force = false,
    ): int {
        if (!preg_match(DemoData::NAME_PATTERN, $name)) {
            $io->error(\sprintf('"%s" is not a demo name: use lower-case letters, digits and hyphens, like "week-1".', $name));

            return Command::INVALID;
        }

        $speech = Uuid::isValid($id) ? $this->speeches->find(Uuid::fromString($id)) : null;
        if (null === $speech) {
            $io->error(\sprintf('No Speech with ID %s.', $id));

            return Command::FAILURE;
        }

        try {
            $files = $this->demoData->export($speech, $name, $force);
        } catch (DemoDataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Saved "%s" as demo data.', $speech->getDisplayTitle()));
        $io->listing($files);

        return Command::SUCCESS;
    }
}
