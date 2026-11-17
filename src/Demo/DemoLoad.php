<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Speech;

/**
 * What app:load-demo did with one demo file.
 */
final readonly class DemoLoad
{
    /**
     * @param bool $created false when a Speech with its ID was already there
     */
    public function __construct(
        public Speech $speech,
        public string $file,
        public bool $created,
    ) {
    }
}
