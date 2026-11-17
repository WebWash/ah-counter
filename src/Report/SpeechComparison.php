<?php

declare(strict_types=1);

namespace App\Report;

/**
 * A baseline Speech A against a later Speech B (SPEC §6.7). Every change is B − A from unrounded
 * rates: negative means fewer Fillers per minute, an improvement.
 */
final readonly class SpeechComparison
{
    /** Decimal places in toArray(): enough to show a change between two 1-decimal rates. */
    public const int DISPLAY_PRECISION = 2;

    public float $fillersPerMinuteChange;

    /**
     * @param list<FillerTypeChange> $byType       every Filler Type in either Speech, most improved first, ties alphabetical
     * @param string|null            $mostImproved the type whose rate fell most, null if none fell
     * @param string|null            $mostWorsened the type whose rate rose most, null if none rose
     */
    public function __construct(
        public float $baselineDurationSeconds,
        public float $durationSeconds,
        public int $baselineFillerCount,
        public int $fillerCount,
        public float $baselineFillersPerMinute,
        public float $fillersPerMinute,
        public array $byType,
        public ?string $mostImproved,
        public ?string $mostWorsened,
    ) {
        $this->fillersPerMinuteChange = $fillersPerMinute - $baselineFillersPerMinute;
    }

    public function type(string $type): FillerTypeChange
    {
        foreach ($this->byType as $change) {
            if ($change->type === $type) {
                return $change;
            }
        }

        throw new \OutOfBoundsException(\sprintf('Neither Speech has the Filler Type "%s".', $type));
    }

    /**
     * @return array{baseline: array{durationSeconds: float, fillerCount: int, fillersPerMinute: float}, speech: array{durationSeconds: float, fillerCount: int, fillersPerMinute: float}, fillersPerMinuteChange: float, byType: list<array{type: string, baselineCount: int, count: int, baselinePerMinute: float, perMinute: float, perMinuteChange: float}>, mostImproved: string|null, mostWorsened: string|null}
     */
    public function toArray(): array
    {
        return [
            'baseline' => [
                'durationSeconds' => $this->baselineDurationSeconds,
                'fillerCount' => $this->baselineFillerCount,
                'fillersPerMinute' => round($this->baselineFillersPerMinute, self::DISPLAY_PRECISION),
            ],
            'speech' => [
                'durationSeconds' => $this->durationSeconds,
                'fillerCount' => $this->fillerCount,
                'fillersPerMinute' => round($this->fillersPerMinute, self::DISPLAY_PRECISION),
            ],
            'fillersPerMinuteChange' => round($this->fillersPerMinuteChange, self::DISPLAY_PRECISION),
            'byType' => array_map(static fn (FillerTypeChange $change): array => $change->toArray(), $this->byType),
            'mostImproved' => $this->mostImproved,
            'mostWorsened' => $this->mostWorsened,
        ];
    }
}
