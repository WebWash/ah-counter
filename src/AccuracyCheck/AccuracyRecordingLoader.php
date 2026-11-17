<?php

declare(strict_types=1);

namespace App\AccuracyCheck;

/**
 * Finds the Recordings for app:accuracy-check: each `name.webm|mp4|ogg` Recording, plus its hand
 * count in `name.json` (Filler Type => count) when someone has made one.
 */
final class AccuracyRecordingLoader
{
    /** Extension => MIME type, as stored for a Recording (SPEC §6.8). */
    private const array AUDIO_TYPES = [
        'webm' => 'audio/webm',
        'mp4' => 'audio/mp4',
        'ogg' => 'audio/ogg',
    ];

    /**
     * @return list<AccuracyRecording> sorted by name in natural order (clip-2 before clip-10)
     *
     * @throws \InvalidArgumentException when the directory is missing or a hand count has no Recording
     */
    public function load(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException(\sprintf('Recording directory "%s" does not exist.', $dir));
        }

        $recordings = [];
        foreach (self::AUDIO_TYPES as $extension => $mimeType) {
            foreach (glob(\sprintf('%s/*.%s', $dir, $extension)) ?: [] as $audioPath) {
                $name = basename($audioPath, '.'.$extension);
                $jsonPath = \sprintf('%s/%s.json', $dir, $name);
                $recordings[$name] = new AccuracyRecording($name, $audioPath, $mimeType, is_file($jsonPath) ? $this->readExpected($jsonPath) : null);
            }
        }

        foreach (glob($dir.'/*.json') ?: [] as $jsonPath) {
            $name = basename($jsonPath, '.json');
            if (!isset($recordings[$name])) {
                throw new \InvalidArgumentException(\sprintf('%s.json has no recording (expected %1$s.webm, .mp4 or .ogg next to it).', $name));
            }
        }

        $recordings = array_values($recordings);
        usort($recordings, static fn (AccuracyRecording $a, AccuracyRecording $b): int => strnatcmp($a->name, $b->name));

        return $recordings;
    }

    /**
     * @return array<string, int>
     */
    private function readExpected(string $path): array
    {
        $invalid = new \InvalidArgumentException(\sprintf('%s must be a JSON object of Filler Type => whole number, e.g. {"um": 4, "uh": 6}.', basename($path)));

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalid;
        }

        if (!\is_array($data) || (array_is_list($data) && [] !== $data)) {
            throw $invalid;
        }

        $expected = [];
        foreach ($data as $type => $count) {
            if (!\is_int($count) || $count < 0) {
                throw $invalid;
            }
            $expected[mb_strtolower(trim((string) $type))] = $count;
        }

        return $expected;
    }
}
