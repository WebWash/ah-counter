<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * A demo name must be safe as a file name: lower-case letters, digits and hyphens.
 */
final class InvalidDemoName extends DemoDataException
{
    public function __construct(string $name)
    {
        parent::__construct(\sprintf('"%s" is not a demo name: use lower-case letters, digits and hyphens, like "week-1".', $name));
    }
}
