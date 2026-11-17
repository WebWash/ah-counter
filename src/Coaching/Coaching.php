<?php

declare(strict_types=1);

namespace App\Coaching;

/**
 * Short, constructive advice based only on the Fillers found in one Speech (SPEC §6.5).
 *
 * Also the structured-output DTO the coach answers with, and the shape stored in Speech::$coaching.
 */
final readonly class Coaching
{
    /**
     * @param string       $summary  at most 60 words
     * @param list<string> $patterns at most 3 short observations, each citing a time or a count
     * @param list<string> $tips     at most 3 concrete techniques
     */
    public function __construct(
        public string $summary,
        public array $patterns,
        public array $tips,
    ) {
    }

    /**
     * @return array{summary: string, patterns: list<string>, tips: list<string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'patterns' => $this->patterns,
            'tips' => $this->tips,
        ];
    }

    /**
     * @param array<string, mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['summary'] ?? ''),
            array_values(array_map(strval(...), (array) ($data['patterns'] ?? []))),
            array_values(array_map(strval(...), (array) ($data['tips'] ?? []))),
        );
    }
}
