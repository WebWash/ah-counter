<?php

declare(strict_types=1);

namespace App\Speech;

final readonly class StoredRecording
{
    public function __construct(
        public string $filename,
        public string $mimeType,
    ) {
    }
}
