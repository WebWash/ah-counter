<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Transcription\Transcript;

/**
 * Decides which Candidates are Fillers, from the words around them (SPEC §6.3).
 */
interface CandidateJudge
{
    /**
     * @param list<FillerOccurrence> $occurrences every Filler Sound and Candidate in the Transcript
     *
     * @return list<FillerOccurrence> the same occurrences, in the same order, with each Candidate's
     *                                verdict decided (or left unjudged); Filler Sounds unchanged
     *
     * @throws \Throwable short-lived failures (rate limits, malformed output) are retried by Messenger
     */
    public function judge(Transcript $transcript, array $occurrences): array;
}
