<?php

declare(strict_types=1);

namespace App\Speech;

use App\Entity\Speech;

/**
 * Only done or failed Speeches may be deleted, so the worker never writes to a deleted row (SPEC §5).
 */
final class SpeechStillProcessing extends \RuntimeException
{
    public function __construct(Speech $speech)
    {
        parent::__construct(\sprintf('This Speech is still %s. You can delete it once it has finished.', $speech->getStatus()->value));
    }
}
