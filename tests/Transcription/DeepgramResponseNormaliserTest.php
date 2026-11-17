<?php

declare(strict_types=1);

namespace App\Tests\Transcription;

use App\Transcription\DeepgramResponseNormaliser;
use App\Transcription\MalformedTranscription;
use App\Transcription\Word;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeepgramResponseNormaliserTest extends TestCase
{
    /**
     * Word counts and durations read from the saved Nova-2 responses (ticket 04); Filler Sounds
     * as listed in ADR 0001's evidence table.
     *
     * @return iterable<string, array{string, int, float, list<string>}>
     */
    public static function fixtures(): iterable
    {
        yield 'clip-1 (filler-heavy)' => ['clip-1', 77, 51.24, ['uh', 'uh', 'um', 'uh']];
        yield 'clip-2 (natural)' => ['clip-2', 98, 52.26, []];
        yield 'clip-3 (few fillers)' => ['clip-3', 83, 55.8, ['uh', 'uh']];
    }

    /**
     * @param list<string> $fillerSounds
     */
    #[DataProvider('fixtures')]
    public function testNormalisesASavedDeepgramResponse(string $clip, int $wordCount, float $duration, array $fillerSounds): void
    {
        $transcript = (new DeepgramResponseNormaliser())->normalise($this->fixture($clip));

        self::assertCount($wordCount, $transcript->words);
        self::assertSame($duration, $transcript->durationSeconds);

        $sounds = array_values(array_filter(
            array_map(static fn (Word $word): string => $word->text, $transcript->words),
            static fn (string $text): bool => \in_array($text, ['um', 'uh'], true),
        ));
        self::assertSame($fillerSounds, $sounds, 'Filler Sounds are kept, in order');

        $previousStart = -1.0;
        foreach ($transcript->words as $word) {
            self::assertGreaterThanOrEqual($previousStart, $word->start, 'timestamps go up');
            self::assertGreaterThanOrEqual($word->start, $word->end);
            self::assertMatchesRegularExpression('/^[a-z0-9\'-]+$/', $word->text, 'text is lower-case without punctuation');
            $previousStart = $word->start;
        }
    }

    public function testKeepsThePunctuatedSpellingAndConfidence(): void
    {
        $transcript = (new DeepgramResponseNormaliser())->normalise($this->fixture('clip-1'));

        $first = $transcript->words[0];
        self::assertSame('hello', $first->text);
        self::assertSame('Hello.', $first->punctuated);

        $uh = $transcript->words[5];
        self::assertSame('uh', $uh->text);
        self::assertSame('Uh,', $uh->punctuated);
        self::assertGreaterThan(0.0, $uh->confidence);
    }

    public function testFallsBackToThePlainWordWhenThereIsNoPunctuatedSpelling(): void
    {
        $transcript = (new DeepgramResponseNormaliser())->normalise($this->response([
            ['word' => 'Hello', 'start' => 0.1, 'end' => 0.4, 'confidence' => 0.9],
        ]));

        self::assertSame('hello', $transcript->words[0]->text);
        self::assertSame('Hello', $transcript->words[0]->punctuated);
    }

    public function testAcceptsAResponseWithNoWords(): void
    {
        $transcript = (new DeepgramResponseNormaliser())->normalise($this->response([]));

        self::assertSame([], $transcript->words);
        self::assertSame(12.5, $transcript->durationSeconds);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedResponses(): iterable
    {
        $word = ['word' => 'so', 'punctuated_word' => 'So', 'start' => 0.1, 'end' => 0.4, 'confidence' => 0.9];
        $channels = static fn (mixed $words): array => ['channels' => [['alternatives' => [['words' => $words]]]]];

        yield 'no metadata' => [['results' => $channels([$word])]];
        yield 'duration not a number' => [['metadata' => ['duration' => 'long'], 'results' => $channels([$word])]];
        yield 'no results' => [['metadata' => ['duration' => 1.0]]];
        yield 'no channels' => [['metadata' => ['duration' => 1.0], 'results' => []]];
        yield 'words not a list' => [['metadata' => ['duration' => 1.0], 'results' => $channels('so')]];
        yield 'word without text' => [['metadata' => ['duration' => 1.0], 'results' => $channels([['start' => 0.1, 'end' => 0.4]])]];
        yield 'word without start' => [['metadata' => ['duration' => 1.0], 'results' => $channels([['word' => 'so', 'end' => 0.4]])]];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('malformedResponses')]
    public function testRejectsAMalformedResponse(array $response): void
    {
        $this->expectException(MalformedTranscription::class);

        (new DeepgramResponseNormaliser())->normalise($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $clip): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/deepgram/'.$clip.'.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $words
     *
     * @return array<string, mixed>
     */
    private function response(array $words): array
    {
        return [
            'metadata' => ['duration' => 12.5],
            'results' => ['channels' => [['alternatives' => [['transcript' => '', 'words' => $words]]]]],
        ];
    }
}
