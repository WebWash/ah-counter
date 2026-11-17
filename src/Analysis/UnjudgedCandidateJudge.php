<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Transcription\Transcript;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Leaves every Candidate unjudged, so only Filler Sounds count. Placeholder until the AI judge
 * (ticket 14) replaces this alias.
 */
#[AsAlias(CandidateJudge::class)]
final class UnjudgedCandidateJudge implements CandidateJudge
{
    public function judge(Transcript $transcript, array $occurrences): array
    {
        return $occurrences;
    }
}
