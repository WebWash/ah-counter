<?php

declare(strict_types=1);

namespace App\Transcription;

use App\Processing\PermanentFailure;

/**
 * Turns a Recording into a Transcript with word timestamps, keeping filler sounds (SPEC §6.1).
 */
interface Transcriber
{
    /**
     * @param string $mimeType the stored base MIME type of the Recording (SPEC §6.8)
     *
     * @throws PermanentFailure for failures retrying can't fix; any other exception is short-lived
     */
    public function transcribe(string $path, string $mimeType): Transcript;
}
