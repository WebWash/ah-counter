<?php

declare(strict_types=1);

namespace App\Coaching;

/**
 * A short speaking drill aimed at the Filler Types one Speech shows most (SPEC §6.6). Generated
 * on request and never stored.
 *
 * Also the structured-output DTO the model answers with.
 */
final readonly class PracticeExercise
{
    /**
     * @param list<string> $focus   the Filler Types it targets; empty for a general fluency exercise
     * @param list<string> $prompts 3 speaking prompts
     */
    public function __construct(
        public string $title,
        public array $focus,
        public string $instructions,
        public array $prompts,
        public int $durationMinutes,
    ) {
    }
}
