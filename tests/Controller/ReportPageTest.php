<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Analysis\FillerKind;
use App\Analysis\FillerOccurrence;
use App\Analysis\Verdict;
use App\Entity\Speech;
use App\Tests\Fake\FakeTranscriber;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The done view of /speeches/{id} (SPEC §7 "Report page layout", ticket 13).
 */
final class ReportPageTest extends WebTestCase
{
    use RecreatesDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::recreateDatabase();
    }

    public function testShowsTheStatisticsOfADoneSpeech(): void
    {
        $crawler = $this->requestReport($this->createDoneSpeech());

        self::assertResponseIsSuccessful();
        self::assertSame('0:30', $this->stat($crawler, 'duration'));
        self::assertSame('3', $this->stat($crawler, 'total'));
        self::assertSame('6.0', $this->stat($crawler, 'per-minute'));
        self::assertSame('um', $this->stat($crawler, 'top-type'));
        self::assertSelectorNotExists('[data-controller="speech-status"]', 'a done Speech is not polled');
    }

    public function testThePlayerGetsTheRecordingAndOneMarkerPerCountedFiller(): void
    {
        $speech = $this->createDoneSpeech();
        $crawler = $this->requestReport($speech);

        $player = $crawler->filter('[data-controller="report-player"]');
        self::assertCount(1, $player);
        self::assertSame('/speeches/'.$speech->getId().'/recording', $player->filter('audio')->attr('src'));

        $markers = json_decode((string) $player->attr('data-report-player-fillers-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertEquals([
            ['start' => 2.0, 'end' => 2.5, 'kind' => 'sound', 'type' => 'um'],
            ['start' => 6.0, 'end' => 6.5, 'kind' => 'phrase', 'type' => 'like'],
            ['start' => 11.0, 'end' => 11.5, 'kind' => 'sound', 'type' => 'um'],
        ], $markers, 'rejected and unjudged Candidates get no marker');
    }

    public function testBreaksTheFillersDownByFillerType(): void
    {
        $crawler = $this->requestReport($this->createDoneSpeech());

        $bars = $crawler->filter('[data-breakdown] [data-filler-type]');
        self::assertSame(['um', 'like'], $bars->each(static fn (Crawler $bar) => $bar->attr('data-filler-type')));
        self::assertSame(['2', '1'], $bars->each(static fn (Crawler $bar) => trim($bar->filter('[data-count]')->text())));
        self::assertSame(['sound', 'phrase'], $bars->each(static fn (Crawler $bar) => $bar->attr('data-kind')));
    }

    public function testTheTranscriptMarksFillersAndFadesCandidatesThatDoNotCount(): void
    {
        $crawler = $this->requestReport($this->createDoneSpeech());

        $transcript = $crawler->filter('[data-transcript]');
        self::assertCount(12, $transcript->filter('button[data-start]'), 'every word can be clicked; "you know" is one button');
        self::assertSame('So I um think it was like huge and you know um done.', preg_replace('/\s+/', ' ', trim($transcript->text())));

        $um = $transcript->filter('[data-verdict="filler"][data-kind="sound"]')->first();
        self::assertSame('um', trim($um->text()));
        self::assertSame('2', $um->attr('data-start'));

        $like = $transcript->filter('[data-verdict="filler"][data-kind="phrase"]');
        self::assertSame('like', trim($like->text()));

        $youKnow = $transcript->filter('[data-verdict="not_filler"]');
        self::assertSame('you know', preg_replace('/\s+/', ' ', trim($youKnow->text())));
        self::assertStringContainsString('asks the listener', (string) $youKnow->attr('title'));

        $so = $transcript->filter('[data-verdict="unjudged"]');
        self::assertSame('So', trim($so->text()));
        self::assertNotEmpty($so->attr('title'));
    }

    public function testListsEachCountedFillerWithItsTimeAndContext(): void
    {
        $crawler = $this->requestReport($this->createDoneSpeech());

        $rows = $crawler->filter('[data-filler-list] tbody tr');
        self::assertCount(3, $rows);
        self::assertSame(['2', '6', '11'], $rows->each(static fn (Crawler $row) => $row->attr('data-start')));
        self::assertSame('0:02', trim($rows->first()->filter('td')->eq(0)->text()));
        self::assertSame('um', trim($rows->first()->filter('td')->eq(1)->text()));
        self::assertStringContainsString('So I um think it was', preg_replace('/\s+/', ' ', $rows->first()->filter('td')->eq(2)->text()));
        self::assertSame('click->report-player#seek', $rows->first()->attr('data-action'), 'the whole row seeks');
        self::assertCount(1, $rows->first()->filter('button'), 'and has a button for keyboard users');
    }

    public function testASpeechWithoutFillersGetsAFriendlyEmptyState(): void
    {
        $speech = $this->createDoneSpeech(['It', 'went', 'very', 'smoothly.'], []);
        $crawler = $this->requestReport($speech);

        self::assertResponseIsSuccessful();
        self::assertSame('0', $this->stat($crawler, 'total'));
        self::assertSame('0.0', $this->stat($crawler, 'per-minute'));
        self::assertSame('—', $this->stat($crawler, 'top-type'));
        self::assertSelectorTextContains('[data-no-fillers]', 'No Fillers');
        self::assertSelectorNotExists('[data-breakdown]');
        self::assertSelectorNotExists('[data-filler-list]');
        self::assertSame('[]', $crawler->filter('[data-controller="report-player"]')->attr('data-report-player-fillers-value'));
    }

    public function testADoneSpeechCanBeDeletedWithAnInlineConfirmation(): void
    {
        $speech = $this->createDoneSpeech();
        $crawler = $this->requestReport($speech);

        $form = $crawler->filter(\sprintf('details form[action="/speeches/%s/delete"]', $speech->getId()));
        self::assertCount(1, $form);
        self::assertStringContainsString('Yes, delete it', $form->text());
    }

    /**
     * "So I um think it was like huge and you know um done.", 30 s long: um ×2 and a judged
     * "like" count; "you know" was rejected and "So" left unjudged.
     *
     * @param list<string>|null           $spoken
     * @param list<FillerOccurrence>|null $fillers
     */
    private function createDoneSpeech(?array $spoken = null, ?array $fillers = null): Speech
    {
        $spoken ??= ['So', 'I', 'um', 'think', 'it', 'was', 'like', 'huge', 'and', 'you', 'know', 'um', 'done.'];
        $transcript = FakeTranscriber::transcript($spoken, 30.0);
        $fillers ??= [
            new FillerOccurrence('so', FillerKind::Phrase, 0.0, 0.5, 0, 0, Verdict::Unjudged),
            new FillerOccurrence('um', FillerKind::Sound, 2.0, 2.5, 2, 2, Verdict::Filler),
            new FillerOccurrence('like', FillerKind::Phrase, 6.0, 6.5, 6, 6, Verdict::Filler, 'stands in for a pause'),
            new FillerOccurrence('you know', FillerKind::Phrase, 9.0, 10.5, 9, 10, Verdict::NotFiller, 'asks the listener'),
            new FillerOccurrence('um', FillerKind::Sound, 11.0, 11.5, 11, 11, Verdict::Filler),
        ];

        $speech = new Speech(Uuid::v4(), 'speech.webm', 'audio/webm', 29.5, 'Club speech');
        $speech->recordTranscript($transcript);
        $speech->recordFillers($fillers);
        $speech->recordCoaching([]);
        $speech->markDone();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($speech);
        $entityManager->flush();

        return $speech;
    }

    private function requestReport(Speech $speech): Crawler
    {
        return $this->client->request('GET', '/speeches/'.$speech->getId());
    }

    private function stat(Crawler $crawler, string $name): string
    {
        return trim($crawler->filter(\sprintf('[data-stat="%s"] [data-value]', $name))->text());
    }
}
