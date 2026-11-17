<?php

declare(strict_types=1);

namespace App\Command;

use App\AccuracyCheck\AccuracyRecording;
use App\AccuracyCheck\AccuracyTranscriber;
use App\AccuracyCheck\AccuracyRecordingLoader;
use App\AccuracyCheck\FillerSoundCounter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Runs the Recordings in tests/accuracy/ through a real transcription provider and
 * compares the Filler Sounds it keeps with their hand count, if any (SPEC §10, ticket 04).
 *
 * For now it calls the provider directly and counts Filler Sounds in its raw words. Ticket 09
 * swaps in DeepgramTranscriber, and ticket 14 FillerDetector and, optionally, the Candidate judge.
 */
#[AsCommand(name: 'app:accuracy-check', description: 'Compare a transcriber\'s Filler Sounds with the Recordings and hand counts in tests/accuracy/ (real API calls)')]
final class AccuracyCheckCommand extends Command
{
    private const string RECORDING_DIR = 'tests/accuracy';
    private const string FIXTURE_DIR = 'tests/Fixtures/deepgram';

    /**
     * @param ServiceProviderInterface<AccuracyTranscriber> $transcribers keyed by --provider name
     */
    public function __construct(
        private readonly AccuracyRecordingLoader $recordingLoader,
        private readonly FillerSoundCounter $counter,
        #[AutowireLocator(AccuracyTranscriber::TAG)]
        private readonly ServiceProviderInterface $transcribers,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Transcription provider to test')]
        string $provider = 'deepgram',
        #[Option(description: 'Deepgram only: also send smart_format=true')]
        bool $smartFormat = false,
        #[Option(description: 'Deepgram only: save each raw response to tests/Fixtures/deepgram/<name>.json')]
        bool $saveFixtures = false,
    ): int {
        if (!$this->transcribers->has($provider)) {
            $io->error(\sprintf('Unknown provider "%s". Available: %s.', $provider, implode(', ', array_keys($this->transcribers->getProvidedServices()))));

            return Command::FAILURE;
        }

        if (($smartFormat || $saveFixtures) && 'deepgram' !== $provider) {
            $io->error('--smart-format and --save-fixtures only apply to --provider=deepgram.');

            return Command::FAILURE;
        }

        if ($smartFormat && $saveFixtures) {
            $io->error('Fixtures must use the production options (SPEC §6.1), so --save-fixtures can\'t be combined with --smart-format.');

            return Command::FAILURE;
        }

        $recordingDir = $this->projectDir.'/'.self::RECORDING_DIR;
        try {
            $recordings = $this->recordingLoader->load($recordingDir);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $recordings) {
            $io->error(\sprintf('No Recordings found. Save each Recording as %s/clip-1.webm, optionally with clip-1.json holding the hand count, e.g. {"um": 4, "uh": 6, "like": 2}.', self::RECORDING_DIR));

            return Command::FAILURE;
        }

        $transcriber = $this->transcribers->get($provider);
        $providerOptions = $smartFormat ? ['smart_format' => 'true'] : [];
        $io->title(\sprintf('Accuracy check: %s%s, %d Recording(s)', $provider, $smartFormat ? ' (smart_format)' : '', \count($recordings)));

        $ok = true;
        foreach ($recordings as $recording) {
            $io->section($recording->name);

            try {
                $transcription = $transcriber->transcribe($recording, $providerOptions);
            } catch (\Throwable $e) {
                $io->error(\sprintf('%s failed for %s: %s', $provider, $recording->name, $e->getMessage()));
                if ($io->isDebug()) {
                    $io->writeln((string) $e);
                }
                $ok = false;

                continue;
            }

            if ($io->isVerbose()) {
                $io->text('Transcript: '.$transcription->text);
            }

            $io->table(['Filler Type', 'Expected', 'Detected', 'Diff'], $this->rows($recording, $this->counter->count($transcription->words)));

            if ($saveFixtures) {
                $this->saveFixture($io, $recording, $transcription->response);
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, int> $detected
     *
     * @return list<list<string|int>|TableSeparator>
     */
    private function rows(AccuracyRecording $recording, array $detected): array
    {
        $handCount = $recording->expected;
        $rows = [];
        $expectedTotal = 0;
        $detectedTotal = 0;

        foreach ($this->counter->fillerSoundTypes() as $type) {
            $expected = $handCount[$type] ?? 0;
            $found = $detected[$type] ?? 0;
            if (0 === $found && !isset($handCount[$type])) {
                continue;
            }
            $rows[] = null === $handCount ? [$type, '?', $found, ''] : [$type, $expected, $found, $this->diff($found - $expected)];
            $expectedTotal += $expected;
            $detectedTotal += $found;
        }

        // Filler Phrases need context, which only FillerDetector and the judge provide (tickets 10, 14).
        foreach (array_diff_key($handCount ?? [], array_flip($this->counter->fillerSoundTypes())) as $type => $expected) {
            $rows[] = [$type, $expected, 'n/a', ''];
        }

        $rows[] = new TableSeparator();
        $rows[] = null === $handCount
            ? ['All Filler Sounds', '?', $detectedTotal, '']
            : ['All Filler Sounds', $expectedTotal, $detectedTotal, $this->diff($detectedTotal - $expectedTotal)];

        return $rows;
    }

    private function diff(int $diff): string
    {
        return $diff > 0 ? '+'.$diff : (string) $diff;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function saveFixture(SymfonyStyle $io, AccuracyRecording $recording, array $response): void
    {
        $path = \sprintf('%s/%s/%s.json', $this->projectDir, self::FIXTURE_DIR, $recording->name);
        $this->filesystem->dumpFile($path, json_encode($response, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        $io->text(\sprintf('Saved fixture %s/%s.json', self::FIXTURE_DIR, $recording->name));
    }
}
