<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log record, with {placeholders} interpolated, grouped by level.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var array<string, list<string>> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        $this->records[(string) $level][] = strtr((string) $message, $replacements);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->records['warning'] ?? [];
    }
}
