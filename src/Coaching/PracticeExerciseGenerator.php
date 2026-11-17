<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Report\Report;

/**
 * Writes a Practice Exercise for one Speech (SPEC §6.6).
 */
interface PracticeExerciseGenerator
{
    /**
     * @param string|null $focusFillerType the Filler Type to practise; defaults to the Report's most
     *                                     frequent one. With no Fillers at all, a general fluency exercise
     *
     * @throws FillerTypeNotInSpeech when the Speech has no Filler of the focus type
     */
    public function generate(Report $report, ?string $focusFillerType = null): PracticeExercise;
}
