<?php

declare(strict_types=1);

namespace App\Smoke;

/**
 * Root structured-output DTO for `app:ai-smoke`. The nested list proves that
 * `response_format`, `asObject()` and nested DTO denormalisation work end to end.
 */
final readonly class FillerTally
{
    /**
     * @param list<FillerCount> $fillers
     */
    public function __construct(
        public array $fillers,
        public int $total,
    ) {
    }
}
