<?php

declare(strict_types=1);

namespace App\Report;

use App\Entity\Speech;

/**
 * A Report needs the Speech's duration and Fillers, which the pipeline hasn't saved yet.
 */
final class ReportNotAvailable extends \RuntimeException
{
    public function __construct(Speech $speech)
    {
        parent::__construct(\sprintf('Speech %s has no Report yet: it is still %s.', $speech->getId()->toRfc4122(), $speech->getStatus()->value));
    }
}
