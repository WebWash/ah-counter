<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Coaching\FillerTypeNotInSpeech;
use App\Coaching\PracticeExercise;
use App\Coaching\PracticeExerciseGenerator;
use App\Report\Report;

/**
 * Returns a fixed Practice Exercise for the focus it was given, or throws the queued exception.
 * Like the real generator, it rejects a focus the Speech doesn't show.
 */
final class FakePracticeExerciseGenerator implements PracticeExerciseGenerator
{
    public ?Report $lastReport = null;

    public ?string $lastFocus = null;

    private ?\Throwable $failure = null;

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function generate(Report $report, ?string $focusFillerType = null): PracticeExercise
    {
        $this->lastReport = $report;
        $this->lastFocus = $focusFillerType;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $focus = $focusFillerType ?? $report->topType;
        if (null !== $focus && !\array_key_exists($focus, $report->byType)) {
            throw new FillerTypeNotInSpeech($focus, array_map(strval(...), array_keys($report->byType)));
        }

        return new PracticeExercise('Pause drill', null === $focus ? [] : [$focus], 'Pause instead.', ['One', 'Two', 'Three'], 5);
    }
}
