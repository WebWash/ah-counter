<?php

declare(strict_types=1);

namespace App\Tests\Analysis;

use App\Analysis\FillerDetector;
use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Transcription\DeepgramResponseNormaliser;
use App\Transcription\Transcript;
use App\Transcription\Word;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FillerDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fillerSounds(): iterable
    {
        yield 'um' => ['um', 'um'];
        yield 'umm' => ['umm', 'um'];
        yield 'Ummm,' => ['Ummm,', 'um'];
        yield 'uh' => ['uh', 'uh'];
        yield 'Uh,' => ['Uh,', 'uh'];
        yield 'uhh' => ['uhh', 'uh'];
        yield 'ah' => ['ah', 'ah'];
        yield 'Ahh...' => ['Ahh...', 'ah'];
        yield 'er' => ['er', 'er'];
        yield 'err' => ['err', 'er'];
        yield 'erm' => ['erm', 'erm'];
        yield 'errm' => ['errm', 'erm'];
        yield 'hmm' => ['hmm', 'hmm'];
        yield 'HMM.' => ['HMM.', 'hmm'];
        yield 'hmmm' => ['hmmm', 'hmm'];
        yield 'hm' => ['hm', 'hmm'];
        yield 'mhmm (Deepgram)' => ['mhmm', 'hmm'];
        yield 'mm (Deepgram)' => ['mm', 'hmm'];
    }

    #[DataProvider('fillerSounds')]
    public function testAFillerSoundIsAlwaysAFillerOfItsCanonicalType(string $spoken, string $type): void
    {
        $fillers = $this->detect(['well', $spoken, 'then']);

        $sounds = array_values(array_filter($fillers, static fn (FillerOccurrence $f): bool => FillerKind::Sound === $f->kind));
        self::assertCount(1, $sounds);
        self::assertSame($type, $sounds[0]->type);
        self::assertSame(Verdict::Filler, $sounds[0]->verdict);
        self::assertSame(1, $sounds[0]->wordStart);
        self::assertSame(1, $sounds[0]->wordEnd);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFillers(): iterable
    {
        yield 'uh-huh' => ['uh-huh'];
        yield 'Uh-uh.' => ['Uh-uh.'];
        yield 'mm-mm' => ['mm-mm'];
        yield 'nuh-uh' => ['nuh-uh'];
        yield 'a real word starting like a sound' => ['umbrella'];
        yield 'her' => ['her'];
        yield 'ham' => ['ham'];
    }

    #[DataProvider('notFillers')]
    public function testYesNoGruntsAndOrdinaryWordsAreNotFillers(string $spoken): void
    {
        self::assertSame([], $this->detect(['I', $spoken, 'agree']));
    }

    /**
     * @return iterable<string, array{list<string>, string, int, int}>
     */
    public static function fillerPhrases(): iterable
    {
        yield 'like' => [['I', 'like', 'pizza'], 'like', 1, 1];
        yield 'so' => [['So,', 'we', 'started'], 'so', 0, 0];
        yield 'you know' => [['it', 'was', 'you', 'know', 'fine'], 'you know', 2, 3];
        yield 'I mean' => [['I', 'mean,', 'it', 'worked'], 'i mean', 0, 1];
        yield 'sort of' => [['it', 'sort', 'of', 'worked'], 'sort of', 1, 2];
        yield 'kind of followed by punctuation' => [['it', 'was', 'kind', 'of,', 'big'], 'kind of', 2, 3];
        yield 'basically' => [['Basically', 'yes'], 'basically', 0, 0];
        yield 'actually' => [['it', 'actually', 'works'], 'actually', 1, 1];
        yield 'right' => [['turn', 'right.'], 'right', 1, 1];
        yield 'well' => [['Well,', 'okay'], 'well', 0, 0];
    }

    /**
     * @param list<string> $words
     */
    #[DataProvider('fillerPhrases')]
    public function testAFillerPhraseIsACandidateLeftUnjudged(array $words, string $type, int $wordStart, int $wordEnd): void
    {
        $fillers = $this->detect($words);

        self::assertCount(1, $fillers);
        self::assertSame($type, $fillers[0]->type);
        self::assertSame(FillerKind::Phrase, $fillers[0]->kind);
        self::assertSame(Verdict::Unjudged, $fillers[0]->verdict);
        self::assertSame($wordStart, $fillers[0]->wordStart);
        self::assertSame($wordEnd, $fillers[0]->wordEnd);
    }

    public function testAMultiWordPhraseIsOneCandidateTimedFromItsFirstToItsLastWord(): void
    {
        $transcript = new Transcript([
            new Word('it', 'It', 0.0, 0.2, 0.9),
            new Word('was', 'was,', 0.3, 0.5, 0.9),
            new Word('you', 'you', 0.6, 0.7, 0.9),
            new Word('know', 'know,', 0.8, 1.1, 0.9),
            new Word('huge', 'huge.', 1.2, 1.6, 0.9),
        ], 2.0);

        $fillers = (new FillerDetector())->detect($transcript);

        self::assertCount(1, $fillers, '"you know" is one Candidate, not "you" + "know"');
        self::assertSame('you know', $fillers[0]->type);
        self::assertSame(0.6, $fillers[0]->start);
        self::assertSame(1.1, $fillers[0]->end);
    }

    public function testOccurrencesNeverOverlap(): void
    {
        // "I mean" takes "I"; the following "like" and "you know" are separate Candidates.
        $fillers = $this->detect(['I', 'mean', 'like', 'you', 'know', 'so', 'so']);

        self::assertSame(['i mean', 'like', 'you know', 'so', 'so'], array_map(static fn (FillerOccurrence $f): string => $f->type, $fillers));

        $lastEnd = -1;
        foreach ($fillers as $filler) {
            self::assertGreaterThan($lastEnd, $filler->wordStart);
            $lastEnd = $filler->wordEnd;
        }
    }

    public function testOccurrencesAreOrderedByTime(): void
    {
        $fillers = $this->detect(['um', 'so', 'like', 'uh', 'you', 'know', 'hmm']);

        self::assertSame(['um', 'so', 'like', 'uh', 'you know', 'hmm'], array_map(static fn (FillerOccurrence $f): string => $f->type, $fillers));
        $starts = array_map(static fn (FillerOccurrence $f): float => $f->start, $fillers);
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts);
    }

    public function testAnEmptyTranscriptHasNoFillers(): void
    {
        self::assertSame([], (new FillerDetector())->detect(new Transcript([], 30.0)));
    }

    /**
     * Ticket 04 has no hand count yet, so these are the Nova-2 Filler Sound counts from
     * ADR 0001's evidence table: detection must find every one Deepgram kept.
     *
     * @return iterable<string, array{string, array<string, int>}>
     */
    public static function deepgramFixtures(): iterable
    {
        yield 'clip-1' => ['clip-1', ['uh' => 3, 'um' => 1]];
        yield 'clip-2' => ['clip-2', []];
        yield 'clip-3' => ['clip-3', ['uh' => 2]];
    }

    /**
     * @param array<string, int> $expectedSounds
     */
    #[DataProvider('deepgramFixtures')]
    public function testFindsEveryFillerSoundInASavedDeepgramResponse(string $clip, array $expectedSounds): void
    {
        $response = json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/deepgram/'.$clip.'.json'), true, flags: \JSON_THROW_ON_ERROR);
        $fillers = (new FillerDetector())->detect((new DeepgramResponseNormaliser())->normalise($response));

        $sounds = [];
        foreach ($fillers as $filler) {
            if (FillerKind::Sound === $filler->kind) {
                $sounds[$filler->type] = ($sounds[$filler->type] ?? 0) + 1;
            }
        }
        ksort($sounds);

        self::assertSame($expectedSounds, $sounds);
    }

    public function testAnOccurrenceRoundTripsThroughItsStoredShape(): void
    {
        $occurrence = new FillerOccurrence('you know', FillerKind::Phrase, 12.34, 12.71, 41, 42, Verdict::NotFiller, 'used literally');

        $stored = $occurrence->toArray();

        self::assertSame([
            'type' => 'you know',
            'kind' => 'phrase',
            'start' => 12.34,
            'end' => 12.71,
            'wordStart' => 41,
            'wordEnd' => 42,
            'verdict' => 'not_filler',
            'reason' => 'used literally',
        ], $stored);
        self::assertEquals($occurrence, FillerOccurrence::fromArray(json_decode(json_encode($stored, \JSON_THROW_ON_ERROR), true)));
    }

    /**
     * Words spaced one second apart, with the given text as both plain and punctuated spelling.
     *
     * @param list<string> $spoken
     *
     * @return list<FillerOccurrence>
     */
    private function detect(array $spoken): array
    {
        $words = [];
        foreach ($spoken as $i => $text) {
            $words[] = new Word($text, $text, (float) $i, $i + 0.5, 0.9);
        }

        return (new FillerDetector())->detect(new Transcript($words, (float) \count($words)));
    }
}
