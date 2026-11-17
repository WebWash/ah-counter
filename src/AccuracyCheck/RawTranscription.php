<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

/**
 * What a provider returned for one Recording, before any Filler detection.
 */
final readonly class RawTranscription
{
    /**
     * @param list<string>         $words    one entry per word, as the provider wrote it
     * @param array<string, mixed> $response the provider's full decoded response, kept for fixtures
     */
    public function __construct(
        public array $words,
        public string $text,
        public array $response,
    ) {
    }
}
