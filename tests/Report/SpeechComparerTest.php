<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Report\FillerTypeChange;
use App\Report\Report;
use App\Report\SpeechComparer;
use PHPUnit\Framework\TestCase;

/**
 * SpeechComparer: baseline Speech A against a later Speech B, every difference B − A (SPEC §6.7).
 */
final class SpeechComparerTest extends TestCase
{
    public function testDifferencesAreTheLaterSpeechMinusTheBaselineSoNegativeIsAnImprovement(): void
    {
        // A: 6 Fillers in 2 minutes = 3.0/min. B: 2 Fillers in 1 minute = 2.0/min.
        $comparison = (new SpeechComparer())->compare(
            $this->report(120.0, ['um' => 4, 'like' => 2]),
            $this->report(60.0, ['um' => 1, 'like' => 1]),
        );

        self::assertSame(120.0, $comparison->baselineDurationSeconds);
        self::assertSame(60.0, $comparison->durationSeconds);
        self::assertEqualsWithDelta(3.0, $comparison->baselineFillersPerMinute, 1e-9);
        self::assertEqualsWithDelta(2.0, $comparison->fillersPerMinute, 1e-9);
        self::assertEqualsWithDelta(-1.0, $comparison->fillersPerMinuteChange, 1e-9);

        $um = $comparison->type('um');
        self::assertSame(4, $um->baselineCount);
        self::assertSame(1, $um->count);
        self::assertEqualsWithDelta(2.0, $um->baselinePerMinute, 1e-9);
        self::assertEqualsWithDelta(1.0, $um->perMinute, 1e-9);
        self::assertEqualsWithDelta(-1.0, $um->perMinuteChange, 1e-9);
        // like: 1.0/min → 1.0/min, unchanged even though the count halved.
        self::assertEqualsWithDelta(0.0, $comparison->type('like')->perMinuteChange, 1e-9);

        self::assertSame('um', $comparison->mostImproved);
        self::assertNull($comparison->mostWorsened, '"like" did not get worse');
    }

    public function testChangesComeFromUnroundedRates(): void
    {
        // A: 1 in 70 s = 0.857…/min (0.9 rounded). B: 1 in 80 s = 0.75/min (0.8 rounded).
        $comparison = (new SpeechComparer())->compare($this->report(70.0, ['um' => 1]), $this->report(80.0, ['um' => 1]));

        self::assertEqualsWithDelta(0.75 - 60 / 70, $comparison->fillersPerMinuteChange, 1e-9);
    }

    public function testAFillerTypeInOnlyOneSpeechCountsAsZeroInTheOther(): void
    {
        $comparison = (new SpeechComparer())->compare(
            $this->report(60.0, ['um' => 2]),
            $this->report(60.0, ['so' => 3]),
        );

        self::assertSame(['um', 'so'], array_map(static fn (FillerTypeChange $c): string => $c->type, $comparison->byType), 'most improved first');
        self::assertSame(0, $comparison->type('um')->count);
        self::assertEqualsWithDelta(-2.0, $comparison->type('um')->perMinuteChange, 1e-9);
        self::assertSame(0, $comparison->type('so')->baselineCount);
        self::assertEqualsWithDelta(3.0, $comparison->type('so')->perMinuteChange, 1e-9);
        self::assertSame('um', $comparison->mostImproved);
        self::assertSame('so', $comparison->mostWorsened);
    }

    public function testNothingImprovedWhenEveryTypeGotWorse(): void
    {
        $comparison = (new SpeechComparer())->compare(
            $this->report(60.0, ['um' => 1, 'like' => 1]),
            $this->report(60.0, ['um' => 2, 'like' => 4]),
        );

        self::assertNull($comparison->mostImproved);
        self::assertSame('like', $comparison->mostWorsened);
    }

    public function testNothingImprovedOrWorsenedWhenNothingChanged(): void
    {
        $comparison = (new SpeechComparer())->compare(
            $this->report(60.0, ['um' => 2]),
            $this->report(120.0, ['um' => 4]),
        );

        self::assertNull($comparison->mostImproved);
        self::assertNull($comparison->mostWorsened);
    }

    public function testASpeechWithoutFillers(): void
    {
        $comparison = (new SpeechComparer())->compare(
            $this->report(60.0, ['um' => 3]),
            $this->report(45.0, []),
        );

        self::assertSame(0.0, $comparison->fillersPerMinute);
        self::assertEqualsWithDelta(-3.0, $comparison->fillersPerMinuteChange, 1e-9);
        self::assertSame('um', $comparison->mostImproved);
        self::assertNull($comparison->mostWorsened);

        $none = (new SpeechComparer())->compare($this->report(30.0, []), $this->report(45.0, []));
        self::assertSame([], $none->byType);
        self::assertNull($none->mostImproved);
        self::assertNull($none->mostWorsened);
    }

    public function testTiesAreBrokenAlphabetically(): void
    {
        $comparison = (new SpeechComparer())->compare(
            $this->report(60.0, ['um' => 2, 'like' => 2, 'uh' => 1, 'so' => 1]),
            $this->report(60.0, ['um' => 1, 'like' => 1, 'uh' => 2, 'so' => 2]),
        );

        self::assertSame('like', $comparison->mostImproved);
        self::assertSame('so', $comparison->mostWorsened);
        self::assertSame(['like', 'um', 'so', 'uh'], array_map(static fn (FillerTypeChange $c): string => $c->type, $comparison->byType));
    }

    public function testToArrayRoundsForDisplay(): void
    {
        $array = (new SpeechComparer())->compare($this->report(70.0, ['um' => 1]), $this->report(80.0, ['um' => 1]))->toArray();

        self::assertSame(['durationSeconds' => 70.0, 'fillerCount' => 1, 'fillersPerMinute' => 0.86], $array['baseline']);
        self::assertSame(['durationSeconds' => 80.0, 'fillerCount' => 1, 'fillersPerMinute' => 0.75], $array['speech']);
        self::assertSame(-0.11, $array['fillersPerMinuteChange']);
        self::assertSame([
            ['type' => 'um', 'baselineCount' => 1, 'count' => 1, 'baselinePerMinute' => 0.86, 'perMinute' => 0.75, 'perMinuteChange' => -0.11],
        ], $array['byType']);
        self::assertSame('um', $array['mostImproved']);
        self::assertNull($array['mostWorsened']);
    }

    /**
     * @param array<string, int> $byType
     */
    private function report(float $durationSeconds, array $byType): Report
    {
        $total = array_sum($byType);
        arsort($byType);

        return new Report($durationSeconds, $total, $total / ($durationSeconds / 60), round($total / ($durationSeconds / 60), 1), $byType, $total, 0, array_key_first($byType), null);
    }
}
