<?php

declare(strict_types=1);

namespace App\Coaching;

/**
 * A Practice Exercise was asked to focus on a Filler Type the Speech doesn't show.
 */
final class FillerTypeNotInSpeech extends \InvalidArgumentException
{
    /**
     * @param list<string> $typesInSpeech the Filler Types it does show, most frequent first
     */
    public function __construct(
        public readonly string $focusFillerType,
        public readonly array $typesInSpeech,
    ) {
        parent::__construct([] === $typesInSpeech
            ? \sprintf('This Speech has no "%s" Fillers; it has no Fillers at all. Leave the focus out for a general fluency exercise.', $focusFillerType)
            : \sprintf('This Speech has no "%s" Fillers. Its Filler Types are: %s.', $focusFillerType, implode(', ', $typesInSpeech)));
    }
}
