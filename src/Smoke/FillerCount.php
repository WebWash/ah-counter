<?php

declare(strict_types=1);

namespace App\Smoke;

/**
 * One Filler Type and how often it occurred. Structured-output DTO for `app:ai-smoke`.
 */
final readonly class FillerCount
{
    public function __construct(
        public string $word,
        public int $count,
    ) {
    }
}
