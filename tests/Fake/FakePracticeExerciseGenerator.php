<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Coaching\PracticeExercise;
use App\Coaching\PracticeExerciseGenerator;
use App\Coaching\PracticeFocus;
use App\Report\Report;

/**
 * Returns a fixed Practice Exercise for the focus it was given, or throws the queued exception.
 * It picks the focus the way the real generator does (PracticeFocus).
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

        $focus = PracticeFocus::choose($report, $focusFillerType);

        return new PracticeExercise('Pause drill', null === $focus ? [] : [$focus], 'Pause instead.', ['One', 'Two', 'Three'], 5);
    }
}
