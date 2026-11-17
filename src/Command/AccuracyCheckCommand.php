<?php

declare(strict_types=1);

namespace App\Command;

use App\AccuracyCheck\AccuracyRecording;
use App\AccuracyCheck\AccuracyTranscriber;
use App\AccuracyCheck\AccuracyRecordingLoader;
use App\Analysis\CandidateJudge;
use App\Analysis\FillerDetector;
use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Lexicon;
use App\Analysis\Verdict;
use App\Transcription\Word;
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
 * Runs the Recordings in tests/accuracy/ through a real transcription provider and the app's
 * FillerDetector, optionally the Candidate judge too, and compares the Fillers found with their
 * hand count, if any (SPEC §10, tickets 04 and 14).
 *
 * Deepgram goes through the app's DeepgramTranscriber (ticket 09); Whisper is called directly.
 * Without --judge, Filler Phrases show only how many Candidates were found.
 */
#[AsCommand(name: 'app:accuracy-check', description: 'Compare the Fillers found in tests/accuracy/ Recordings with their hand counts (real API calls)')]
final class AccuracyCheckCommand extends Command
{
    private const string RECORDING_DIR = 'tests/accuracy';
    private const string FIXTURE_DIR = 'tests/Fixtures/deepgram';

    /**
     * @param ServiceProviderInterface<AccuracyTranscriber> $transcribers keyed by --provider name
     */
    public function __construct(
        private readonly AccuracyRecordingLoader $recordingLoader,
        private readonly FillerDetector $detector,
        private readonly CandidateJudge $judge,
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
        #[Option(description: 'Also judge the Filler Phrase Candidates with the AI judge (AI_JUDGE_MODEL)')]
        bool $judge = false,
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
        $io->title(\sprintf('Accuracy check: %s%s%s, %d Recording(s)', $provider, $smartFormat ? ' (smart_format)' : '', $judge ? ' + Candidate judge' : '', \count($recordings)));

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

            if ($saveFixtures) {
                $this->saveFixture($io, $recording, $transcription->response);
            }

            if ($io->isVerbose()) {
                $io->text('Transcript: '.$transcription->transcript->text());
            }

            $occurrences = $this->detector->detect($transcription->transcript);
            if ($judge) {
                try {
                    $occurrences = $this->judge->judge($transcription->transcript, $occurrences);
                } catch (\Throwable $e) {
                    $io->error(\sprintf('The Candidate judge failed for %s: %s', $recording->name, $e->getMessage()));
                    if ($io->isDebug()) {
                        $io->writeln((string) $e);
                    }
                    $ok = false;

                    continue;
                }

                if ($io->isVerbose()) {
                    $this->printVerdicts($io, $transcription->transcript->words, $occurrences);
                }
            }

            $io->table(['Filler Type', 'Expected', 'Detected', 'Diff', 'Candidates'], $this->rows($recording, $occurrences, $judge));
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * One row per Filler Type that was found or hand-counted, then the totals. Filler Phrases count
     * as detected only once judged; their Candidates column shows how many the detector found.
     *
     * @param list<FillerOccurrence> $occurrences
     *
     * @return list<list<string|int>|TableSeparator>
     */
    private function rows(AccuracyRecording $recording, array $occurrences, bool $judged): array
    {
        $sounds = array_values(array_filter($occurrences, static fn (FillerOccurrence $o): bool => FillerKind::Sound === $o->kind));
        $phrases = array_values(array_filter($occurrences, static fn (FillerOccurrence $o): bool => FillerKind::Phrase === $o->kind));

        [$soundRows, $soundTotal] = $this->group('All Filler Sounds', array_keys(Lexicon::FILLER_SOUNDS), $sounds, $recording->expected, true);
        [$phraseRows, $phraseTotal] = $this->group('All Filler Phrases', Lexicon::FILLER_PHRASES, $phrases, $recording->expected, $judged);

        // Hand-counted types the lexicon doesn't know, so they can never be detected.
        $unknown = [];
        foreach (array_diff_key($recording->expected ?? [], array_flip([...array_keys(Lexicon::FILLER_SOUNDS), ...Lexicon::FILLER_PHRASES])) as $type => $expected) {
            $unknown[] = $this->row((string) $type, $expected, null, '');
        }

        $rows = [...$soundRows, ...$phraseRows, ...$unknown, new TableSeparator(), $soundTotal[0], $phraseTotal[0]];
        if ($judged) {
            $expected = null === $recording->expected ? null : $soundTotal[1] + $phraseTotal[1];
            $rows[] = $this->row('All Fillers', $expected, $soundTotal[2] + $phraseTotal[2], '');
        }

        return $rows;
    }

    /**
     * The rows of one kind (Filler Sounds or Filler Phrases) and their total.
     *
     * @param list<string>            $types       the kind's Filler Types, in lexicon order
     * @param list<FillerOccurrence>  $occurrences this kind only
     * @param array<string, int>|null $handCount
     * @param bool                    $counted     false for unjudged Candidates: detected shows n/a
     *
     * @return array{list<list<string|int>>, array{list<string|int>, int, int}} rows, then [total row, expected, detected]
     */
    private function group(string $label, array $types, array $occurrences, ?array $handCount, bool $counted): array
    {
        $isSound = 'All Filler Sounds' === $label;
        $rows = [];
        $expectedTotal = 0;
        $foundTotal = 0;
        $candidateTotal = 0;

        foreach ($types as $type) {
            $ofType = array_filter($occurrences, static fn (FillerOccurrence $o): bool => $o->type === $type);
            $found = \count(array_filter($ofType, static fn (FillerOccurrence $o): bool => $o->isFiller()));
            if ([] === $ofType && !isset($handCount[$type])) {
                continue;
            }
            $expected = $handCount[$type] ?? 0;
            $rows[] = $this->row($type, null === $handCount ? null : $expected, $counted ? $found : null, $isSound ? '' : \count($ofType));
            $expectedTotal += $expected;
            $foundTotal += $found;
            $candidateTotal += \count($ofType);
        }

        $total = $this->row($label, null === $handCount ? null : $expectedTotal, $counted ? $foundTotal : null, $isSound ? '' : $candidateTotal);

        return [$rows, [$total, $expectedTotal, $foundTotal]];
    }

    /**
     * @param int|null $expected null when the Recording has no hand count
     * @param int|null $detected null when this check doesn't count the type (unjudged Candidates)
     *
     * @return list<string|int>
     */
    private function row(string $label, ?int $expected, ?int $detected, int|string $candidates): array
    {
        $diff = null === $expected || null === $detected ? '' : $this->diff($detected - $expected);

        return [$label, $expected ?? '?', $detected ?? 'n/a', $diff, $candidates];
    }

    /**
     * @param list<Word> $words
     * @param list<FillerOccurrence>         $occurrences
     */
    private function printVerdicts(SymfonyStyle $io, array $words, array $occurrences): void
    {
        $rows = [];
        foreach ($occurrences as $occurrence) {
            if (FillerKind::Phrase !== $occurrence->kind) {
                continue;
            }
            $context = \array_slice($words, max(0, $occurrence->wordStart - 4), min($occurrence->wordStart, 4) + $occurrence->wordEnd - $occurrence->wordStart + 5);
            $rows[] = [
                \sprintf('%.1f s', $occurrence->start),
                $occurrence->type,
                match ($occurrence->verdict) {
                    Verdict::Filler => 'Filler',
                    Verdict::NotFiller => 'not a Filler',
                    Verdict::Unjudged => 'unjudged',
                },
                implode(' ', array_map(static fn (Word $word): string => $word->punctuated, $context)),
                $occurrence->reason ?? '',
            ];
        }

        if ([] !== $rows) {
            $io->table(['Time', 'Candidate', 'Verdict', 'Context', 'Reason'], $rows);
        }
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
