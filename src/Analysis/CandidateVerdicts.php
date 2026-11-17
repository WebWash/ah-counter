<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Root structured-output DTO for the Candidate judge (SPEC §6.3).
 */
final readonly class CandidateVerdicts
{
    /**
     * @param list<CandidateVerdict> $verdicts one per marked Candidate
     */
    public function __construct(
        public array $verdicts,
    ) {
    }
}
