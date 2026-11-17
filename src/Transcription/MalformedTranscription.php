<?php

declare(strict_types=1);

namespace App\Transcription;

/**
 * The transcription provider answered, but not in the shape we expect. Treated as short-lived
 * (SPEC §5), so Messenger retries it.
 */
final class MalformedTranscription extends \UnexpectedValueException
{
}
