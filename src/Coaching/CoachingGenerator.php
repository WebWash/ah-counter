<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Analysis\FillerOccurrence;
use App\Report\Report;
use App\Transcription\Transcript;

/**
 * Writes short, constructive Coaching from the Fillers found in one Speech (SPEC §6.5).
 */
interface CoachingGenerator
{
    /**
     * @param list<FillerOccurrence> $fillers every Filler Sound and judged Candidate
     *
     * @throws \Throwable short-lived failures (rate limits, malformed output) are retried by Messenger
     */
    public function generate(Report $report, Transcript $transcript, array $fillers): Coaching;
}
