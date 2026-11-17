<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * The judge's decision on one marked Candidate (structured output, SPEC §6.3).
 */
final readonly class CandidateVerdict
{
    /**
     * @param string $id       the Candidate's marker ID, e.g. "c7"
     * @param bool   $isFiller true when the phrase adds no meaning in its sentence
     * @param string $reason   one short sentence explaining the decision
     */
    public function __construct(
        public string $id,
        public bool $isFiller,
        public string $reason,
    ) {
    }
}
