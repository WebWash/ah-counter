<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

use App\Transcription\Transcript;

/**
 * What a provider returned for one Recording, before any Filler detection.
 */
final readonly class RawTranscription
{
    /**
     * @param array<string, mixed> $response the provider's full decoded response, kept for fixtures
     */
    public function __construct(
        public Transcript $transcript,
        public array $response,
    ) {
    }
}
