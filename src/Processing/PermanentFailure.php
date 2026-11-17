<?php

declare(strict_types=1);

namespace App\Processing;

/**
 * A processing failure that retrying can't fix (SPEC §5): the provider refused the request
 * (400/401/403 and other 4xx), the Recording is unreadable, or the duration or no-speech rule
 * failed. ProcessSpeechHandler turns it into an UnrecoverableMessageHandlingException.
 *
 * The exception message is for the log; $userMessage is what the speaker sees.
 */
final class PermanentFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $userMessage,
        string $logMessage = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct('' === $logMessage ? $userMessage : $logMessage, 0, $previous);
    }
}
