<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Coaching\Coaching;
use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Report\ReportBuilder;
use App\Report\ReportNotAvailable;
use App\Transcription\Transcript;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReportBuilderTest extends TestCase
{
    public function testTwelveFillersInThreeMinutesIsFourPerMinute(): void
    {
        $speech = $this->speech(180.0, array_fill(0, 12, $this->sound('um')));

        $report = (new ReportBuilder())->build($speech);

        self::assertSame(180.0, $report->durationSeconds);
        self::assertSame(12, $report->totalFillers);
        self::assertSame(4.0, $report->fillersPerMinute);
        self::assertSame(4.0, $report->fillersPerMinuteRounded);
    }

    public function testKeepsTheUnroundedRateAndRoundsTheDisplayedOneToOneDecimal(): void
    {
        // 7 Fillers in 61 s = 6.885… per minute.
        $speech = $this->speech(61.0, array_fill(0, 7, $this->sound('uh')));

        $report = (new ReportBuilder())->build($speech);

        self::assertEqualsWithDelta(6.8852, $report->fillersPerMinute, 0.0001);
        self::assertSame(6.9, $report->fillersPerMinuteRounded);
    }

    public function testASpeechWithoutFillersHasNoTopType(): void
    {
        $report = (new ReportBuilder())->build($this->speech(42.0, []));

        self::assertSame(0, $report->totalFillers);
        self::assertSame(0.0, $report->fillersPerMinute);
        self::assertSame(0.0, $report->fillersPerMinuteRounded);
        self::assertSame([], $report->byType);
        self::assertNull($report->topType);
        self::assertSame(0, $report->soundCount);
        self::assertSame(0, $report->phraseCount);
    }

    public function testOnlyFillersCountNotRejectedOrUnjudgedCandidates(): void
    {
        $speech = $this->speech(60.0, [
            $this->sound('um'),
            $this->phrase('like', Verdict::Filler),
            $this->phrase('like', Verdict::NotFiller),
            $this->phrase('so', Verdict::Unjudged),
            $this->phrase('you know', Verdict::NotFiller),
        ]);

        $report = (new ReportBuilder())->build($speech);

        self::assertSame(2, $report->totalFillers);
        self::assertSame(['like' => 1, 'um' => 1], $report->byType);
        self::assertSame(1, $report->soundCount);
        self::assertSame(1, $report->phraseCount);
        self::assertSame(2.0, $report->fillersPerMinute);
    }

    public function testCountsByFillerTypeAreSortedByCountThenAlphabetically(): void
    {
        $speech = $this->speech(120.0, [
            $this->sound('uh'),
            $this->phrase('so', Verdict::Filler),
            $this->sound('um'),
            $this->sound('uh'),
            $this->phrase('like', Verdict::Filler),
            $this->sound('um'),
            $this->sound('uh'),
            $this->phrase('you know', Verdict::Filler),
        ]);

        $report = (new ReportBuilder())->build($speech);

        self::assertSame(['uh' => 3, 'um' => 2, 'like' => 1, 'so' => 1, 'you know' => 1], $report->byType);
        self::assertSame('uh', $report->topType);
        self::assertSame(5, $report->soundCount);
        self::assertSame(3, $report->phraseCount);
    }

    public function testATieForTheTopTypeGoesToTheAlphabeticallyFirst(): void
    {
        $speech = $this->speech(60.0, [$this->sound('um'), $this->phrase('like', Verdict::Filler)]);

        self::assertSame('like', (new ReportBuilder())->build($speech)->topType);
    }

    public function testBuildsForASpeechStillInTheCoachingStep(): void
    {
        $speech = $this->speech(60.0, [$this->sound('um')]);
        $speech->startStep(SpeechStatus::Coaching);

        $report = (new ReportBuilder())->build($speech);

        self::assertSame(1, $report->totalFillers);
        self::assertNull($report->coaching);
    }

    public function testCarriesTheSavedCoaching(): void
    {
        $speech = $this->speech(60.0, [$this->sound('um')]);
        $speech->recordCoaching(new Coaching('One "um" in a minute.', ['"um" once, at 0:00.'], ['Pause instead.']));

        $report = (new ReportBuilder())->build($speech);

        self::assertEquals(new Coaching('One "um" in a minute.', ['"um" once, at 0:00.'], ['Pause instead.']), $report->coaching);
        self::assertSame(['summary' => 'One "um" in a minute.', 'patterns' => ['"um" once, at 0:00.'], 'tips' => ['Pause instead.']], $report->toArray()['coaching']);
    }

    public function testIsNotAvailableBeforeTheFillersAreSaved(): void
    {
        $speech = new Speech(Uuid::v4(), 'a.webm', 'audio/webm', 60.0);
        $speech->recordTranscript(new Transcript([], 60.0));

        $this->expectException(ReportNotAvailable::class);

        (new ReportBuilder())->build($speech);
    }

    public function testIsNotAvailableWithoutADuration(): void
    {
        $speech = new Speech(Uuid::v4(), 'a.webm', 'audio/webm', null);
        $speech->recordFillers([]);

        self::assertNull((new ReportBuilder())->buildIfAvailable($speech));

        $this->expectException(ReportNotAvailable::class);
        (new ReportBuilder())->build($speech);
    }

    public function testExportsItsStatisticsAsAnArray(): void
    {
        $report = (new ReportBuilder())->build($this->speech(90.0, [$this->sound('um'), $this->sound('um'), $this->phrase('so', Verdict::Filler)]));

        self::assertSame([
            'durationSeconds' => 90.0,
            'totalFillers' => 3,
            'fillersPerMinute' => 2.0,
            'fillersPerMinuteRounded' => 2.0,
            'byType' => ['um' => 2, 'so' => 1],
            'soundCount' => 2,
            'phraseCount' => 1,
            'topType' => 'um',
            'coaching' => null,
        ], $report->toArray());
    }

    /**
     * @param list<FillerOccurrence> $fillers
     */
    private function speech(float $duration, array $fillers): Speech
    {
        $speech = new Speech(Uuid::v4(), 'a.webm', 'audio/webm', $duration);
        $speech->recordFillers($fillers);

        return $speech;
    }

    private function sound(string $type): FillerOccurrence
    {
        return new FillerOccurrence($type, FillerKind::Sound, 1.0, 1.2, 0, 0, Verdict::Filler);
    }

    private function phrase(string $type, Verdict $verdict): FillerOccurrence
    {
        return new FillerOccurrence($type, FillerKind::Phrase, 2.0, 2.4, 1, 1, $verdict, Verdict::Filler === $verdict ? null : 'genuine use');
    }
}
