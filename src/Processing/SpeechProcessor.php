<?php

declare(strict_types=1);

namespace App\Processing;

use App\Analysis\CandidateJudge;
use App\Analysis\FillerDetector;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\CoachingGenerator;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Report\ReportBuilder;
use App\Speech\RecordingStorage;
use App\Speech\SpeechDuration;
use App\Transcription\Transcriber;
use App\Transcription\Transcript;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a Speech through the pipeline (SPEC §5): transcribing → analysing → coaching → done.
 *
 * The next step is the first whose output is still null, so a retried message resumes where the
 * last one failed. Each step's status and output are saved with their own short flush, and no
 * transaction stays open during a provider call.
 */
final readonly class SpeechProcessor
{
    public const string NO_SPEECH_MESSAGE = 'We couldn\'t hear any speech in this recording.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecordingStorage $recordingStorage,
        private Transcriber $transcriber,
        private FillerDetector $fillerDetector,
        private CandidateJudge $candidateJudge,
        private CoachingGenerator $coachingGenerator,
        private ReportBuilder $reportBuilder,
    ) {
    }

    /**
     * @throws PermanentFailure when retrying can't help; any other exception is short-lived
     */
    public function process(Speech $speech): void
    {
        if (null === $speech->getTranscript()) {
            $this->startStep($speech, SpeechStatus::Transcribing);
            $transcript = $this->transcriber->transcribe($this->recordingStorage->path($speech), $speech->getRecordingMimeType());
            $this->assertUsable($transcript);
            $speech->recordTranscript($transcript);
            $this->entityManager->flush();
        }

        if (null === $speech->getFillers()) {
            $this->startStep($speech, SpeechStatus::Analysing);
            $speech->recordFillers($this->findFillers($this->transcriptOf($speech)));
            $this->entityManager->flush();
        }

        if (null === $speech->getCoaching()) {
            $this->startStep($speech, SpeechStatus::Coaching);
            $coaching = $this->coachingGenerator->generate(
                $this->reportBuilder->build($speech),
                $this->transcriptOf($speech),
                $speech->fillerOccurrences() ?? [],
            );
            $speech->recordCoaching($coaching);
            $this->entityManager->flush();
        }

        $speech->markDone();
        $this->entityManager->flush();
    }

    private function startStep(Speech $speech, SpeechStatus $step): void
    {
        $speech->startStep($step);
        $this->entityManager->flush();
    }

    /**
     * The duration and no-speech rules run before the Transcript is saved, so a Retry can never
     * skip them (SPEC §5).
     */
    private function assertUsable(Transcript $transcript): void
    {
        if (!SpeechDuration::isAccepted($transcript->durationSeconds)) {
            throw new PermanentFailure(SpeechDuration::OUT_OF_RANGE_MESSAGE, \sprintf('The Transcriber measured %.2f s.', $transcript->durationSeconds));
        }

        if ([] === $transcript->words) {
            throw new PermanentFailure(self::NO_SPEECH_MESSAGE, 'The Transcriber returned no words.');
        }
    }

    /**
     * Detection, then judging. Returned only once judging has finished, so a half-judged list is
     * never saved.
     *
     * @return list<FillerOccurrence>
     */
    private function findFillers(Transcript $transcript): array
    {
        $occurrences = $this->fillerDetector->detect($transcript);

        $hasCandidates = [] !== array_filter($occurrences, static fn (FillerOccurrence $o): bool => Verdict::Unjudged === $o->verdict);

        return $hasCandidates ? $this->candidateJudge->judge($transcript, $occurrences) : $occurrences;
    }

    private function transcriptOf(Speech $speech): Transcript
    {
        return $speech->transcript() ?? throw new \LogicException('The transcribing step must run first.');
    }
}
