<?php

declare(strict_types=1);

namespace App\Speech;

final class UnsupportedRecording extends \RuntimeException
{
    public const string MESSAGE = 'This Recording is not a supported audio file. Please record it again in Chrome, Safari or Firefox.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
