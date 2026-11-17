<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Speech\InvalidStatusTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

final class SpeechTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testANewSpeechIsPending(): void
    {
        $speech = $this->newSpeech();

        self::assertSame(SpeechStatus::Pending, $speech->getStatus());
        self::assertNull($speech->getError());
    }

    public function testItWalksThroughEveryStepToDone(): void
    {
        $speech = $this->newSpeech();

        $speech->startStep(SpeechStatus::Transcribing);
        $speech->startStep(SpeechStatus::Analysing);
        $speech->startStep(SpeechStatus::Coaching);
        $speech->markDone();

        self::assertSame(SpeechStatus::Done, $speech->getStatus());
    }

    public function testResumingCanSkipAheadFromPending(): void
    {
        $speech = $this->newSpeech();

        $speech->startStep(SpeechStatus::Analysing);

        self::assertSame(SpeechStatus::Analysing, $speech->getStatus());
    }

    public function testReEnteringTheCurrentStepIsAllowed(): void
    {
        $speech = $this->newSpeech();
        $speech->startStep(SpeechStatus::Transcribing);

        $speech->startStep(SpeechStatus::Transcribing);

        self::assertSame(SpeechStatus::Transcribing, $speech->getStatus());
    }

    public function testMovingBackToAnEarlierStepThrows(): void
    {
        $speech = $this->newSpeech();
        $speech->startStep(SpeechStatus::Coaching);

        $this->expectException(InvalidStatusTransition::class);
        $speech->startStep(SpeechStatus::Transcribing);
    }

    public function testStartStepRefusesAStatusThatIsNotAStep(): void
    {
        $speech = $this->newSpeech();

        $this->expectException(InvalidStatusTransition::class);
        $speech->startStep(SpeechStatus::Done);
    }

    public function testNoStepCanStartOnceDone(): void
    {
        $speech = $this->newSpeech();
        $speech->markDone();

        $this->expectException(InvalidStatusTransition::class);
        $speech->startStep(SpeechStatus::Coaching);
    }

    public function testNoStepCanStartWhileFailed(): void
    {
        $speech = $this->newSpeech();
        $speech->markFailed('Something went wrong.');

        $this->expectException(InvalidStatusTransition::class);
        $speech->startStep(SpeechStatus::Transcribing);
    }

    /**
     * @return iterable<string, array{list<SpeechStatus>}>
     */
    public static function statesThatCanFail(): iterable
    {
        yield 'pending' => [[]];
        yield 'transcribing' => [[SpeechStatus::Transcribing]];
        yield 'analysing' => [[SpeechStatus::Analysing]];
        yield 'coaching' => [[SpeechStatus::Coaching]];
    }

    /**
     * @param list<SpeechStatus> $steps
     */
    #[DataProvider('statesThatCanFail')]
    public function testMarkFailedWorksFromAnyStateThatIsNotDone(array $steps): void
    {
        $speech = $this->newSpeech();
        foreach ($steps as $step) {
            $speech->startStep($step);
        }

        $speech->markFailed('We couldn\'t hear any speech in this recording.');

        self::assertSame(SpeechStatus::Failed, $speech->getStatus());
        self::assertSame('We couldn\'t hear any speech in this recording.', $speech->getError());
    }

    public function testMarkFailedRefusesADoneSpeech(): void
    {
        $speech = $this->newSpeech();
        $speech->markDone();

        $this->expectException(InvalidStatusTransition::class);
        $speech->markFailed('Too late.');
    }

    public function testMarkDoneRefusesAFailedSpeech(): void
    {
        $speech = $this->newSpeech();
        $speech->markFailed('Broken.');

        $this->expectException(InvalidStatusTransition::class);
        $speech->markDone();
    }

    public function testDisplayTitleFallsBackToTheCreationDateAndTime(): void
    {
        self::mockTime('2026-11-17 18:05:00');

        $untitled = $this->newSpeech();
        $titled = new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 30.0, 'Club speech');

        self::assertSame('17 Nov 2026, 18:05', $untitled->getDisplayTitle());
        self::assertSame('Club speech', $titled->getDisplayTitle());
    }

    public function testEveryChangeMovesUpdatedAt(): void
    {
        self::mockTime('2026-11-17 10:00:00');
        $speech = $this->newSpeech();

        self::mockTime('2026-11-17 10:00:30');
        $speech->startStep(SpeechStatus::Transcribing);

        self::assertEquals(new \DateTimeImmutable('2026-11-17 10:00:00'), $speech->getCreatedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-17 10:00:30'), $speech->getUpdatedAt());
    }

    private function newSpeech(): Speech
    {
        return new Speech(Uuid::v4(), 'recording.webm', 'audio/webm', 42.5);
    }
}
