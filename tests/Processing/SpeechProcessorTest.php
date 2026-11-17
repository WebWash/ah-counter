<?php

declare(strict_types=1);

namespace App\Tests\Processing;

use App\Analysis\FillerDetector;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Message\ProcessSpeech;
use App\MessageHandler\ProcessSpeechHandler;
use App\Processing\PermanentFailure;
use App\Processing\SpeechProcessor;
use App\Report\ReportBuilder;
use App\Repository\SpeechRepository;
use App\Speech\RecordingStorage;
use App\Tests\Fake\FakeCandidateJudge;
use App\Tests\Fake\FakeCoachingGenerator;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * SpeechProcessor and ProcessSpeechHandler against the real database, with fake providers (SPEC §5, §10).
 */
final class SpeechProcessorTest extends KernelTestCase
{
    use RecreatesDatabase;

    private EntityManagerInterface $entityManager;
    private FakeTranscriber $transcriber;
    private FakeCandidateJudge $judge;
    private FakeCoachingGenerator $coach;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::recreateDatabase();
        $this->transcriber = FakeTranscriber::speaking();
        $this->judge = new FakeCandidateJudge();
        $this->coach = new FakeCoachingGenerator();
    }

    public function testAPendingSpeechIsProcessedToDoneOneSavedStepAtATime(): void
    {
        $speech = $this->createSpeech();
        $seen = [];
        $this->transcriber->onCall(function () use ($speech, &$seen): void { $seen['transcribing'] = $this->saved($speech); });
        $this->judge->onCall(function () use ($speech, &$seen): void { $seen['analysing'] = $this->saved($speech); });
        $this->coach->onCall(function () use ($speech, &$seen): void { $seen['coaching'] = $this->saved($speech); });

        $this->handle($speech);

        // Each provider runs with its step already saved, and the previous step's output saved too.
        self::assertSame(['status' => 'transcribing', 'transcript' => false, 'fillers' => false, 'coaching' => false], $seen['transcribing']);
        self::assertSame(['status' => 'analysing', 'transcript' => true, 'fillers' => false, 'coaching' => false], $seen['analysing']);
        self::assertSame(['status' => 'coaching', 'transcript' => true, 'fillers' => true, 'coaching' => false], $seen['coaching']);

        $done = $this->reload($speech);
        self::assertSame(SpeechStatus::Done, $done->getStatus());
        self::assertSame(20.0, $done->getDurationSeconds(), 'the Transcriber\'s duration replaces the browser\'s');
        self::assertCount(8, $done->getTranscript() ?? []);
        self::assertSame(['so', 'um', 'like'], array_column($done->getFillers() ?? [], 'type'));
        self::assertSame(FakeCoachingGenerator::COACHING, $done->getCoaching());
        self::assertSame(3, $this->coach->lastReport?->totalFillers, 'Coaching gets the Report built from the saved Fillers');
        self::assertSame([1, 1, 1], [$this->transcriber->calls, $this->judge->calls, $this->coach->calls]);
    }

    public function testWithoutCandidatesTheJudgeIsNotCalled(): void
    {
        $this->transcriber = new FakeTranscriber(FakeTranscriber::transcript(['I', 'um', 'think', 'it', 'works.'], 12.0));
        $speech = $this->createSpeech();

        $this->handle($speech);

        self::assertSame(0, $this->judge->calls);
        self::assertSame(['um'], array_column($this->reload($speech)->getFillers() ?? [], 'type'));
    }

    public function testASpeechWithoutFillersStillGetsCoaching(): void
    {
        $this->transcriber = new FakeTranscriber(FakeTranscriber::transcript(['It', 'works', 'very', 'smoothly.'], 12.0));
        $speech = $this->createSpeech();

        $this->handle($speech);

        $done = $this->reload($speech);
        self::assertSame([], $done->getFillers());
        self::assertSame(1, $this->coach->calls);
        self::assertSame(SpeechStatus::Done, $done->getStatus());
    }

    /**
     * @return iterable<string, array{string, array{int, int, int}}>
     */
    public static function failingSteps(): iterable
    {
        // Calls in total over both runs: a failed step runs again, earlier steps never do.
        yield 'transcribing' => ['transcribing', [2, 1, 1]];
        yield 'analysing' => ['analysing', [1, 2, 1]];
        yield 'coaching' => ['coaching', [1, 1, 2]];
    }

    /**
     * @param array{int, int, int} $expectedCalls transcriber, judge, coach
     */
    #[DataProvider('failingSteps')]
    public function testAfterAShortLivedFailureTheNextRunResumesAtTheFailedStep(string $failingStep, array $expectedCalls): void
    {
        $outage = new \RuntimeException('HTTP 503');
        match ($failingStep) {
            'transcribing' => $this->transcriber->failNextCallWith($outage),
            'analysing' => $this->judge->failNextCallWith($outage),
            'coaching' => $this->coach->failNextCallWith($outage),
        };
        $speech = $this->createSpeech();

        try {
            $this->handle($speech);
            self::fail('The first run should fail.');
        } catch (\RuntimeException $e) {
            self::assertSame($outage, $e, 'a short-lived failure is rethrown as it is, so Messenger retries it');
        }
        self::assertSame($failingStep, $this->reload($speech)->getStatus()->value);

        $this->handle($speech);

        self::assertSame(SpeechStatus::Done, $this->reload($speech)->getStatus());
        self::assertSame($expectedCalls, [$this->transcriber->calls, $this->judge->calls, $this->coach->calls]);
    }

    public function testFillersStayUnsavedWhenJudgingFails(): void
    {
        $this->judge->failNextCallWith(new \RuntimeException('Malformed AI output'));
        $speech = $this->createSpeech();

        try {
            $this->handle($speech);
        } catch (\RuntimeException) {
        }

        $reloaded = $this->reload($speech);
        self::assertNotNull($reloaded->getTranscript());
        self::assertNull($reloaded->getFillers(), 'a half-judged list is never saved');
    }

    /**
     * @return iterable<string, array{float, int, string}>
     */
    public static function rejectedTranscripts(): iterable
    {
        yield 'too short' => [8.9, 3, 'between 10 seconds and 5 minutes'];
        yield 'too long' => [301.5, 3, 'between 10 seconds and 5 minutes'];
        yield 'no speech' => [30.0, 0, 'We couldn\'t hear any speech in this recording.'];
    }

    #[DataProvider('rejectedTranscripts')]
    public function testTheDurationAndNoSpeechRulesFailPermanentlyBeforeTheTranscriptIsSaved(float $duration, int $wordCount, string $message): void
    {
        $this->transcriber = new FakeTranscriber(FakeTranscriber::transcript(\array_slice(['hello', 'there', 'friends'], 0, $wordCount), $duration));
        $speech = $this->createSpeech();

        try {
            $this->handle($speech);
            self::fail('Expected a permanent failure.');
        } catch (UnrecoverableMessageHandlingException $e) {
            $permanent = $e->getPrevious();
            self::assertInstanceOf(PermanentFailure::class, $permanent);
            self::assertStringContainsString($message, $permanent->userMessage);
        }

        self::assertNull($this->reload($speech)->getTranscript());
        self::assertSame(0, $this->judge->calls);
    }

    public function testAPermanentProviderFailureIsUnrecoverable(): void
    {
        $this->transcriber->failNextCallWith(new PermanentFailure('The transcription service rejected our API key.'));
        $speech = $this->createSpeech();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle($speech);
    }

    public function testADuplicateDeliveryAfterDoneDoesNothing(): void
    {
        $speech = $this->createSpeech();
        $this->handle($speech);
        $updatedAt = $this->reload($speech)->getUpdatedAt();

        $this->handle($speech);

        self::assertSame([1, 1, 1], [$this->transcriber->calls, $this->judge->calls, $this->coach->calls]);
        self::assertEquals($updatedAt, $this->reload($speech)->getUpdatedAt());
    }

    public function testAMessageForADeletedSpeechDoesNothing(): void
    {
        ($this->handler())(new ProcessSpeech(Uuid::v4()->toRfc4122()));

        self::assertSame(0, $this->transcriber->calls);
    }

    public function testAMessageForAFailedSpeechDoesNothing(): void
    {
        $speech = $this->createSpeech(static fn (Speech $s) => $s->markFailed('Broken.'));

        $this->handle($speech);

        self::assertSame(0, $this->transcriber->calls);
        self::assertSame(SpeechStatus::Failed, $this->reload($speech)->getStatus());
    }

    /**
     * @param (callable(Speech): void)|null $move
     */
    private function createSpeech(?callable $move = null): Speech
    {
        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 21.3);
        if (null !== $move) {
            $move($speech);
        }
        $this->entityManager->persist($speech);
        $this->entityManager->flush();

        return $speech;
    }

    /**
     * Runs one delivery the way the worker does: a fresh identity map, then the handler.
     */
    private function handle(Speech $speech): void
    {
        $this->entityManager->clear();
        ($this->handler())(new ProcessSpeech($speech->getId()->toRfc4122()));
    }

    private function handler(): ProcessSpeechHandler
    {
        $container = self::getContainer();

        return new ProcessSpeechHandler(
            $container->get(SpeechRepository::class),
            new SpeechProcessor(
                $this->entityManager,
                $container->get(RecordingStorage::class),
                $this->transcriber,
                new FillerDetector(),
                $this->judge,
                $this->coach,
                new ReportBuilder(),
            ),
        );
    }

    private function reload(Speech $speech): Speech
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Speech::class, $speech->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * What is saved in the database right now, read past Doctrine's identity map.
     *
     * @return array{status: string, transcript: bool, fillers: bool, coaching: bool}
     */
    private function saved(Speech $speech): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT status, transcript, fillers, coaching FROM speech WHERE id = ?',
            [$speech->getId()->toBinary()],
        );
        self::assertIsArray($row);

        return [
            'status' => $row['status'],
            'transcript' => null !== $row['transcript'],
            'fillers' => null !== $row['fillers'],
            'coaching' => null !== $row['coaching'],
        ];
    }
}
