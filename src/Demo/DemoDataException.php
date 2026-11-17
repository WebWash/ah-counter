<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Demo data can't be exported or loaded; the message says why, for the console.
 */
final class DemoDataException extends \RuntimeException
{
}
