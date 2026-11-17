<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to run the processing pipeline for one Speech (SPEC §5).
 */
final readonly class ProcessSpeech
{
    public function __construct(
        public string $speechId,
    ) {
    }
}
