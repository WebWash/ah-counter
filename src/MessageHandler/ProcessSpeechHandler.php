<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessSpeech;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Placeholder until ticket 12 adds SpeechProcessor: the message is accepted and Speeches stay pending.
 */
#[AsMessageHandler]
final readonly class ProcessSpeechHandler
{
    public function __invoke(ProcessSpeech $message): void
    {
    }
}
