<?php

declare(strict_types=1);

namespace App\Coaching;

use App\Report\Report;
use App\Transcription\Transcript;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Saves empty Coaching so the pipeline can finish. Placeholder until the AI Coaching
 * (ticket 15) replaces this alias; the Report page shows no Coaching section for it.
 */
#[AsAlias(CoachingGenerator::class)]
final class NoCoachingGenerator implements CoachingGenerator
{
    public function generate(Report $report, Transcript $transcript, array $fillers): array
    {
        return [];
    }
}
