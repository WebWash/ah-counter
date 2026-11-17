<?php

declare(strict_types=1);

namespace App\Tests\AccuracyCheck;

use App\AccuracyCheck\FillerSoundCounter;
use PHPUnit\Framework\TestCase;

final class FillerSoundCounterTest extends TestCase
{
    public function testCountsFillerSoundsByFillerTypeIgnoringCaseAndPunctuation(): void
    {
        $words = ['Um,', 'so', 'I', 'uh', 'think', 'UM.', 'it', 'was', '"uh"', 'great', 'ah', 'er', 'erm', 'hmm?'];

        self::assertSame(
            ['um' => 2, 'uh' => 2, 'ah' => 1, 'er' => 1, 'erm' => 1, 'hmm' => 1],
            (new FillerSoundCounter())->count($words),
        );
    }

    public function testNormalisesElongatedSpellingsToTheCanonicalFillerType(): void
    {
        $words = ['umm', 'ummmm', 'uhh', 'ahhh', 'errr', 'ermm', 'hmmm', 'hm'];

        self::assertSame(
            ['um' => 2, 'uh' => 1, 'ah' => 1, 'er' => 1, 'erm' => 1, 'hmm' => 2],
            (new FillerSoundCounter())->count($words),
        );
    }

    public function testMapsDeepgramsMhmmAndMmToHmm(): void
    {
        self::assertSame(['hmm' => 2], (new FillerSoundCounter())->count(['mhmm', 'mm']));
    }

    public function testIgnoresYesNoGruntsAndRealWords(): void
    {
        $words = ['uh-huh', 'uh-uh', 'mm-mm', 'nuh-uh', 'umbrella', 'her', 'a', 'ha', 'like', 'so'];

        self::assertSame([], (new FillerSoundCounter())->count($words));
    }

    public function testCountsNothingInAnEmptyTranscript(): void
    {
        self::assertSame([], (new FillerSoundCounter())->count([]));
    }
}
