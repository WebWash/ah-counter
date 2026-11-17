<?php

declare(strict_types=1);

namespace App\Report;

/**
 * How one Filler Type changed from a baseline Speech to a later one. Rates are unrounded.
 */
final readonly class FillerTypeChange
{
    public float $perMinuteChange;

    public function __construct(
        public string $type,
        public int $baselineCount,
        public int $count,
        public float $baselinePerMinute,
        public float $perMinute,
    ) {
        $this->perMinuteChange = $perMinute - $baselinePerMinute;
    }

    /**
     * @return array{type: string, baselineCount: int, count: int, baselinePerMinute: float, perMinute: float, perMinuteChange: float}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'baselineCount' => $this->baselineCount,
            'count' => $this->count,
            'baselinePerMinute' => round($this->baselinePerMinute, SpeechComparison::DISPLAY_PRECISION),
            'perMinute' => round($this->perMinute, SpeechComparison::DISPLAY_PRECISION),
            'perMinuteChange' => round($this->perMinuteChange, SpeechComparison::DISPLAY_PRECISION),
        ];
    }
}
