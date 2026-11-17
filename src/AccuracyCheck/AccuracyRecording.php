<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

/**
 * One Recording in tests/accuracy/, with its hand count if it has one.
 */
final readonly class AccuracyRecording
{
    /**
     * @param array<string, int>|null $expected hand-counted Fillers (lower-case Filler Type => count),
     *                                        or null when the Recording hasn't been hand-counted
     */
    public function __construct(
        public string $name,
        public string $audioPath,
        public string $mimeType,
        public ?array $expected,
    ) {
    }
}
