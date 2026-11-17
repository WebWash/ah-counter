<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Analysis\CandidateJudge;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Transcription\Transcript;

/**
 * Judges every Candidate a Filler, or throws the queued exception, and counts its calls.
 */
final class FakeCandidateJudge implements CandidateJudge
{
    public int $calls = 0;

    /** @var list<\Throwable> */
    private array $failures = [];

    /** @var (\Closure(): void)|null */
    private ?\Closure $onCall = null;

    public function failNextCallWith(\Throwable $failure): self
    {
        $this->failures[] = $failure;

        return $this;
    }

    /**
     * @param \Closure(): void $onCall runs at the start of every call
     */
    public function onCall(\Closure $onCall): self
    {
        $this->onCall = $onCall;

        return $this;
    }

    public function judge(Transcript $transcript, array $occurrences): array
    {
        ++$this->calls;
        if (null !== $this->onCall) {
            ($this->onCall)();
        }
        if ([] !== $this->failures) {
            throw array_shift($this->failures);
        }

        return array_map(
            static fn (FillerOccurrence $o): FillerOccurrence => Verdict::Unjudged === $o->verdict
                ? new FillerOccurrence($o->type, $o->kind, $o->start, $o->end, $o->wordStart, $o->wordEnd, Verdict::Filler, 'fake judge')
                : $o,
            $occurrences,
        );
    }
}
