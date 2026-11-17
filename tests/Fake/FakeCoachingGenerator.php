<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Coaching\CoachingGenerator;
use App\Report\Report;
use App\Transcription\Transcript;

/**
 * Returns fixed Coaching, or throws the queued exception, and counts its calls.
 */
final class FakeCoachingGenerator implements CoachingGenerator
{
    public const array COACHING = ['summary' => 'Nice pace.', 'patterns' => [], 'tips' => ['Pause instead of saying um.']];

    public int $calls = 0;

    public ?Report $lastReport = null;

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

    public function generate(Report $report, Transcript $transcript, array $fillers): array
    {
        ++$this->calls;
        $this->lastReport = $report;
        if (null !== $this->onCall) {
            ($this->onCall)();
        }
        if ([] !== $this->failures) {
            throw array_shift($this->failures);
        }

        return self::COACHING;
    }
}
