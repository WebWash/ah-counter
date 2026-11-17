<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * The one audio-type table (SPEC §6.8): what the server detects, what the browser must have said,
 * and what gets stored.
 */
enum AudioFormat: string
{
    case Webm = 'audio/webm';
    case Ogg = 'audio/ogg';
    case Mp4 = 'audio/mp4';

    /**
     * Matches a Recording only when the container finfo detects is in the table and agrees with
     * the browser's Blob.type (codec parameters ignored).
     */
    public static function match(string $path, string $browserMimeType): ?self
    {
        $detected = self::fromDetectedContainer(self::detect($path));

        return null !== $detected && $detected === self::fromBrowserType($browserMimeType) ? $detected : null;
    }

    public static function fromDetectedContainer(string $detectedMimeType): ?self
    {
        return match ($detectedMimeType) {
            'video/webm', 'audio/webm' => self::Webm,
            'audio/ogg' => self::Ogg,
            'video/mp4', 'audio/mp4', 'audio/x-m4a' => self::Mp4,
            default => null,
        };
    }

    /**
     * "audio/webm;codecs=opus" → Webm. Only the audio/* types in the table are accepted.
     */
    public static function fromBrowserType(string $browserMimeType): ?self
    {
        $base = strtolower(trim(explode(';', $browserMimeType, 2)[0]));

        return self::tryFrom($base);
    }

    public function mimeType(): string
    {
        return $this->value;
    }

    public function extension(): string
    {
        return match ($this) {
            self::Webm => 'webm',
            self::Ogg => 'ogg',
            self::Mp4 => 'mp4',
        };
    }

    private static function detect(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        return (new \finfo(\FILEINFO_MIME_TYPE))->file($path) ?: '';
    }
}
