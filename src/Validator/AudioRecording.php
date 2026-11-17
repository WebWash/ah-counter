<?php

declare(strict_types=1);

namespace App\Validator;

use App\Speech\UnsupportedRecording;
use Symfony\Component\Validator\Constraint;

/**
 * An uploaded file whose detected container and browser type agree on a row of the SPEC §6.8 table.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class AudioRecording extends Constraint
{
    public string $message = UnsupportedRecording::MESSAGE;
}
