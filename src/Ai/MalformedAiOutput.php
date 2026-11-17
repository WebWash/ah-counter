<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The model answered, but not in the structured shape we asked for. Short-lived (SPEC §5), so
 * Messenger retries it.
 */
final class MalformedAiOutput extends \UnexpectedValueException
{
}
