<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Report\TranscriptMarker;
use App\Report\TranscriptSegment;
use App\Tests\Fake\FakeTranscriber;
use PHPUnit\Framework\TestCase;

final class TranscriptMarkerTest extends TestCase
{
    public function testGroupsEachOccurrenceIntoOneSegmentAndLeavesOtherWordsAlone(): void
    {
        $transcript = FakeTranscriber::transcript(['Um,', 'it', 'was,', 'you', 'know,', 'huge.'], 12.0);
        $um = new FillerOccurrence('um', FillerKind::Sound, 0.0, 0.5, 0, 0, Verdict::Filler);
        $youKnow = new FillerOccurrence('you know', FillerKind::Phrase, 3.0, 4.5, 3, 4, Verdict::NotFiller, 'asks the listener');

        $segments = (new TranscriptMarker())->segments($transcript, [$um, $youKnow]);

        self::assertSame(
            [['Um,'], ['it'], ['was,'], ['you', 'know,'], ['huge.']],
            array_map(static fn (TranscriptSegment $s): array => array_values(array_map(static fn ($w) => $w->punctuated, $s->words)), $segments),
        );
        self::assertSame($um, $segments[0]->occurrence);
        self::assertNull($segments[1]->occurrence);
        self::assertSame($youKnow, $segments[3]->occurrence);
        self::assertSame([3, 4], array_keys($segments[3]->words), 'words keep their Transcript index');
        self::assertSame(3.0, $segments[3]->start());
    }

    public function testAnEmptyTranscriptHasNoSegments(): void
    {
        self::assertSame([], (new TranscriptMarker())->segments(FakeTranscriber::transcript([], 12.0), []));
    }

    public function testGivesAFewWordsOfContextEitherSideOfAFiller(): void
    {
        $transcript = FakeTranscriber::transcript(['So', 'the', 'thing', 'is,', 'um,', 'we', 'ran', 'out', 'of', 'time.'], 12.0);
        $um = new FillerOccurrence('um', FillerKind::Sound, 4.0, 4.5, 4, 4, Verdict::Filler);

        self::assertSame(
            ['before' => '… the thing is,', 'filler' => 'um,', 'after' => 'we ran out …'],
            (new TranscriptMarker())->context($transcript, $um, 3),
        );
    }

    public function testBracketsEachCountedFillerInThePunctuatedText(): void
    {
        $transcript = FakeTranscriber::transcript(['So', 'I', 'um,', 'think', 'it', 'was', 'like', 'huge,', 'you', 'know.', 'Uh'], 12.0);
        $occurrences = [
            new FillerOccurrence('so', FillerKind::Phrase, 0.0, 0.5, 0, 0, Verdict::Unjudged),
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler),
            new FillerOccurrence('you know', FillerKind::Phrase, 8.0, 9.5, 8, 9, Verdict::Filler),
            new FillerOccurrence('uh', FillerKind::Sound, 10.0, 10.5, 10, 10, Verdict::Filler),
        ];

        self::assertSame(
            'So I [um], think it was [like] huge, [you know]. [Uh]',
            (new TranscriptMarker())->bracketed($transcript, $occurrences),
            'punctuation stays outside the brackets; Candidates that do not count are left plain',
        );
    }

    public function testBracketingAnEmptyTranscriptGivesAnEmptyString(): void
    {
        self::assertSame('', (new TranscriptMarker())->bracketed(FakeTranscriber::transcript([], 12.0), []));
    }

    public function testContextStopsAtTheEdgesOfTheTranscript(): void
    {
        $transcript = FakeTranscriber::transcript(['Well,', 'I', 'mean', 'it.'], 12.0);
        $iMean = new FillerOccurrence('i mean', FillerKind::Phrase, 1.0, 2.5, 1, 2, Verdict::Filler);

        self::assertSame(
            ['before' => 'Well,', 'filler' => 'I mean', 'after' => 'it.'],
            (new TranscriptMarker())->context($transcript, $iMean, 3),
        );
    }
}
