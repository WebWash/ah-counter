<?php

declare(strict_types=1);

namespace App\Entity;

use App\Analysis\FillerOccurrence;
use App\Coaching\Coaching;
use App\Repository\SpeechRepository;
use App\Speech\InvalidStatusTransition;
use App\Transcription\Transcript;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One talk recorded in a single session, plus everything derived from it (SPEC §4).
 *
 * Report statistics are not stored: ReportBuilder computes them from fillers and durationSeconds.
 */
#[ORM\Entity(repositoryClass: SpeechRepository::class)]
#[ORM\Index(name: 'speech_created_at_idx', columns: ['created_at'])]
class Speech
{
    public const int TITLE_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH, nullable: true)]
    private ?string $title;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 16, enumType: SpeechStatus::class)]
    private SpeechStatus $status = SpeechStatus::Pending;

    #[ORM\Column(length: 64)]
    private string $recordingFilename;

    #[ORM\Column(length: 32)]
    private string $recordingMimeType;

    /**
     * The browser's timer value at upload, replaced by the Transcriber's duration.
     */
    #[ORM\Column(nullable: true)]
    private ?float $durationSeconds;

    /**
     * Normalised words: list<array{text: string, punctuated: string, start: float, end: float, confidence: float}>.
     * Null until transcribed.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $transcript = null;

    /**
     * Every Filler Sound and Candidate with its final verdict. Null until analysed, [] when none found.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fillers = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $coaching = null;

    /**
     * User-readable failure reason. Never a raw exception message.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $recordingFilename,
        string $recordingMimeType,
        ?float $durationSeconds,
        ?string $title = null,
    ) {
        $this->id = $id;
        $this->recordingFilename = $recordingFilename;
        $this->recordingMimeType = $recordingMimeType;
        $this->durationSeconds = $durationSeconds;
        $this->title = $title;
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Moves to a processing step. Re-entering the current step and skipping ahead (resuming) are
     * allowed; going back to an earlier step is not (SPEC §4 "Resuming").
     */
    public function startStep(SpeechStatus $step): void
    {
        if (!$step->isStep() || $this->status->isFinished() || $step->order() < $this->status->order()) {
            throw InvalidStatusTransition::between($this->status, $step);
        }

        $this->changeStatus($step);
    }

    public function markDone(): void
    {
        if ($this->status->isFinished()) {
            throw InvalidStatusTransition::between($this->status, SpeechStatus::Done);
        }

        $this->changeStatus(SpeechStatus::Done);
    }

    public function markFailed(string $error): void
    {
        if (SpeechStatus::Done === $this->status) {
            throw InvalidStatusTransition::between($this->status, SpeechStatus::Failed);
        }

        $this->changeStatus(SpeechStatus::Failed);
        $this->error = $error;
    }

    /**
     * Saves the transcribing step's output. The Transcriber's duration replaces the browser's.
     */
    public function recordTranscript(Transcript $transcript): void
    {
        $this->transcript = $transcript->toArray();
        $this->durationSeconds = $transcript->durationSeconds;
        $this->updatedAt = now();
    }

    /**
     * Saves the analysing step's output: every Filler Sound and Candidate with its final verdict.
     *
     * @param list<FillerOccurrence> $fillers
     */
    public function recordFillers(array $fillers): void
    {
        $this->fillers = array_map(static fn (FillerOccurrence $filler): array => $filler->toArray(), $fillers);
        $this->updatedAt = now();
    }

    /**
     * Saves the coaching step's output.
     */
    public function recordCoaching(Coaching $coaching): void
    {
        $this->coaching = $coaching->toArray();
        $this->updatedAt = now();
    }

    /**
     * The stored Coaching, or null until the coaching step has run. Speeches processed before AI
     * Coaching existed (ticket 15) stored an empty placeholder, which also reads as null.
     */
    public function coaching(): ?Coaching
    {
        return null === $this->coaching || [] === $this->coaching ? null : Coaching::fromArray($this->coaching);
    }

    /**
     * The stored Transcript, or null until transcribed.
     */
    public function transcript(): ?Transcript
    {
        return null === $this->transcript ? null : Transcript::fromArray($this->transcript, (float) $this->durationSeconds);
    }

    /**
     * The stored Filler Sounds and Candidates, or null until analysed.
     *
     * @return list<FillerOccurrence>|null
     */
    public function fillerOccurrences(): ?array
    {
        return null === $this->fillers ? null : array_map(FillerOccurrence::fromArray(...), $this->fillers);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * The title, or the creation date and time when there is none.
     */
    public function getDisplayTitle(): string
    {
        return $this->title ?? $this->createdAt->format('j M Y, H:i');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStatus(): SpeechStatus
    {
        return $this->status;
    }

    public function getRecordingFilename(): string
    {
        return $this->recordingFilename;
    }

    public function getRecordingMimeType(): string
    {
        return $this->recordingMimeType;
    }

    public function getDurationSeconds(): ?float
    {
        return $this->durationSeconds;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getTranscript(): ?array
    {
        return $this->transcript;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getFillers(): ?array
    {
        return $this->fillers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCoaching(): ?array
    {
        return $this->coaching;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    private function changeStatus(SpeechStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = now();
    }
}
