<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * One detected Filler Sound or Candidate, with its verdict (the SPEC §4 JSON shape stored in
 * Speech::$fillers).
 */
final readonly class FillerOccurrence
{
    /**
     * @param string      $type      Filler Type, lower-case canonical form ("you know")
     * @param int         $wordStart index of the first Transcript word (inclusive)
     * @param int         $wordEnd   index of the last Transcript word (inclusive)
     * @param string|null $reason    the judge's short note, phrases only
     */
    public function __construct(
        public string $type,
        public FillerKind $kind,
        public float $start,
        public float $end,
        public int $wordStart,
        public int $wordEnd,
        public Verdict $verdict,
        public ?string $reason = null,
    ) {
    }

    /**
     * Counted in the Report.
     */
    public function isFiller(): bool
    {
        return Verdict::Filler === $this->verdict;
    }

    /**
     * @return array{type: string, kind: string, start: float, end: float, wordStart: int, wordEnd: int, verdict: string, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'kind' => $this->kind->value,
            'start' => $this->start,
            'end' => $this->end,
            'wordStart' => $this->wordStart,
            'wordEnd' => $this->wordEnd,
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['type'],
            FillerKind::from((string) $data['kind']),
            (float) $data['start'],
            (float) $data['end'],
            (int) $data['wordStart'],
            (int) $data['wordEnd'],
            Verdict::from((string) $data['verdict']),
            isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }
}
