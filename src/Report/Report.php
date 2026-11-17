<?php

declare(strict_types=1);

namespace App\Report;

/**
 * The statistics of one Speech, plus its Coaching (SPEC §6.4). Computed on read, never stored.
 */
final readonly class Report
{
    /**
     * @param float                     $fillersPerMinute        unrounded, for comparisons
     * @param float                     $fillersPerMinuteRounded to 1 decimal, for display
     * @param array<string, int>        $byType                  Filler Type => count, most first, ties alphabetical
     * @param string|null               $topType                 the most frequent Filler Type, null with no Fillers
     * @param array<string, mixed>|null $coaching                null until the coaching step has run
     */
    public function __construct(
        public float $durationSeconds,
        public int $totalFillers,
        public float $fillersPerMinute,
        public float $fillersPerMinuteRounded,
        public array $byType,
        public int $soundCount,
        public int $phraseCount,
        public ?string $topType,
        public ?array $coaching,
    ) {
    }

    /**
     * @return array{durationSeconds: float, totalFillers: int, fillersPerMinute: float, fillersPerMinuteRounded: float, byType: array<string, int>, soundCount: int, phraseCount: int, topType: string|null, coaching: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'durationSeconds' => $this->durationSeconds,
            'totalFillers' => $this->totalFillers,
            'fillersPerMinute' => $this->fillersPerMinute,
            'fillersPerMinuteRounded' => $this->fillersPerMinuteRounded,
            'byType' => $this->byType,
            'soundCount' => $this->soundCount,
            'phraseCount' => $this->phraseCount,
            'topType' => $this->topType,
            'coaching' => $this->coaching,
        ];
    }
}
