<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Message\ProcessSpeech;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * The home list, the processing page, the status JSON and Retry (ticket 08).
 */
final class SpeechProgressTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use RecreatesDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::recreateDatabase();
    }

    /**
     * @return iterable<string, array{callable(Speech): void, string, ?string}>
     */
    public static function everyStatus(): iterable
    {
        yield 'pending' => [static fn (Speech $s) => null, 'pending', null];
        yield 'transcribing' => [static fn (Speech $s) => $s->startStep(SpeechStatus::Transcribing), 'transcribing', null];
        yield 'analysing' => [static fn (Speech $s) => $s->startStep(SpeechStatus::Analysing), 'analysing', null];
        yield 'coaching' => [static fn (Speech $s) => $s->startStep(SpeechStatus::Coaching), 'coaching', null];
        yield 'done' => [static fn (Speech $s) => $s->markDone(), 'done', null];
        yield 'failed' => [static fn (Speech $s) => $s->markFailed('We couldn\'t hear any speech in this recording.'), 'failed', 'We couldn\'t hear any speech in this recording.'];
    }

    /**
     * @param callable(Speech): void $move
     */
    #[DataProvider('everyStatus')]
    public function testTheStatusEndpointReportsTheStatusAndError(callable $move, string $status, ?string $error): void
    {
        $speech = $this->createSpeech(move: $move);

        $this->client->request('GET', '/speeches/'.$speech->getId().'/status');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['status' => $status, 'error' => $error], $this->json());
    }

    public function testAProcessingSpeechShowsTheStepsAndPollsForChanges(): void
    {
        $speech = $this->createSpeech('Club speech', static fn (Speech $s) => $s->startStep(SpeechStatus::Analysing));

        $crawler = $this->client->request('GET', '/speeches/'.$speech->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Club speech');
        $poller = $crawler->filter('[data-controller="speech-status"]');
        self::assertSame('/speeches/'.$speech->getId().'/status', $poller->attr('data-speech-status-url-value'));
        self::assertSame(['transcribing', 'analysing', 'coaching'], $crawler->filter('[data-step]')->each(static fn ($step) => $step->attr('data-step')));
        self::assertSame('current', $crawler->filter('[data-step="analysing"]')->attr('data-step-state'));
        self::assertSame('complete', $crawler->filter('[data-step="transcribing"]')->attr('data-step-state'));
        self::assertSame('upcoming', $crawler->filter('[data-step="coaching"]')->attr('data-step-state'));
        self::assertSelectorNotExists('form[action$="/delete"]');
    }

    public function testAFailedSpeechShowsTheErrorWithRetryAndDelete(): void
    {
        $speech = $this->createSpeech(move: static fn (Speech $s) => $s->markFailed('Deepgram could not be reached.'));

        $crawler = $this->client->request('GET', '/speeches/'.$speech->getId());

        self::assertSelectorTextContains('[role="alert"]', 'Deepgram could not be reached.');
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/speeches/%s/retry"]', $speech->getId())));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/speeches/%s/delete"]', $speech->getId())));
        self::assertSelectorNotExists('[data-controller="speech-status"]');
    }

    public function testRetryingAFailedSpeechClearsTheErrorAndDispatchesOnce(): void
    {
        $speech = $this->createSpeech(move: static fn (Speech $s) => $s->markFailed('Deepgram could not be reached.'));
        $crawler = $this->client->request('GET', '/speeches/'.$speech->getId());
        $retryForm = $crawler->filter(\sprintf('form[action="/speeches/%s/retry"]', $speech->getId()))->form();

        $this->client->submit($retryForm);

        self::assertResponseRedirects('/speeches/'.$speech->getId());
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new ProcessSpeech($speech->getId()->toRfc4122()), $sent[0]->getMessage());

        $this->client->request('GET', '/speeches/'.$speech->getId().'/status');
        self::assertSame(['status' => 'pending', 'error' => null], $this->json());
    }

    public function testASecondRetryStraightAfterIsAConflictAndDispatchesNothing(): void
    {
        $speech = $this->createSpeech(move: static fn (Speech $s) => $s->markFailed('Deepgram could not be reached.'));
        $crawler = $this->client->request('GET', '/speeches/'.$speech->getId());
        $retryForm = $crawler->filter(\sprintf('form[action="/speeches/%s/retry"]', $speech->getId()))->form();
        $this->client->submit($retryForm);

        // A double click: the same form, posted again before the page reloads.
        $this->client->submit($retryForm);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    /**
     * @return iterable<string, array{callable(Speech): void}>
     */
    public static function statusesThatCannotBeRetried(): iterable
    {
        yield 'pending' => [static fn (Speech $s) => null];
        yield 'transcribing' => [static fn (Speech $s) => $s->startStep(SpeechStatus::Transcribing)];
        yield 'done' => [static fn (Speech $s) => $s->markDone()];
    }

    /**
     * @param callable(Speech): void $move
     */
    #[DataProvider('statusesThatCannotBeRetried')]
    public function testRetryingASpeechThatHasNotFailedIsAConflict(callable $move): void
    {
        $failed = $this->createSpeech(move: static fn (Speech $s) => $s->markFailed('Broken.'));
        $token = $this->retryToken($failed);
        $speech = $this->createSpeech(move: $move);

        $this->client->request('POST', '/speeches/'.$speech->getId().'/retry', ['_token' => $token]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testRetryingWithABadCsrfTokenIsRefused(): void
    {
        $speech = $this->createSpeech(move: static fn (Speech $s) => $s->markFailed('Broken.'));

        $this->client->request('POST', '/speeches/'.$speech->getId().'/retry', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function testRetryingAnUnknownSpeechIsNotFound(): void
    {
        $this->client->request('POST', '/speeches/'.Uuid::v4().'/retry');

        self::assertResponseStatusCodeSame(404);
    }

    public function testADoneSpeechShowsAPlaceholderUntilTheReportPageExists(): void
    {
        $speech = $this->createSpeech(move: static fn (Speech $s) => $s->markDone());

        $crawler = $this->client->request('GET', '/speeches/'.$speech->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-controller="speech-status"]');
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/speeches/%s/delete"]', $speech->getId())));
    }

    public function testTheHomeListShowsRecentSpeechesNewestFirst(): void
    {
        self::mockTime('2026-11-17 09:00:00');
        $this->createSpeech('Oldest');
        self::mockTime('2026-11-17 11:00:00');
        $newest = $this->createSpeech(null, static fn (Speech $s) => $s->markFailed('Broken.'));
        self::mockTime('2026-11-17 10:00:00');
        $this->createSpeech('Middle', static fn (Speech $s) => $s->markDone());

        $crawler = $this->client->request('GET', '/');

        $rows = $crawler->filter('[data-speech-list] li');
        self::assertSame(
            ['17 Nov 2026, 11:00', 'Middle', 'Oldest'],
            $rows->each(static fn ($row) => trim($row->filter('[data-speech-title]')->text())),
        );
        self::assertSame(
            ['Failed', 'Done', 'Pending'],
            $rows->each(static fn ($row) => trim($row->filter('[data-speech-status]')->text())),
        );
        self::assertSame('/speeches/'.$newest->getId(), $rows->first()->filter('a')->attr('href'));
    }

    public function testTheHomeListSaysSoWhenThereAreNoSpeeches(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('[data-speech-list-empty]', 'No speeches yet');
    }

    /**
     * @param (callable(Speech): void)|null $move
     */
    private function createSpeech(?string $title = null, ?callable $move = null): Speech
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $speech = new Speech(Uuid::v4(), Uuid::v4().'.webm', 'audio/webm', 42.0, $title);
        if (null !== $move) {
            $move($speech);
        }
        $entityManager->persist($speech);
        $entityManager->flush();

        return $speech;
    }

    private function retryToken(Speech $failedSpeech): string
    {
        $crawler = $this->client->request('GET', '/speeches/'.$failedSpeech->getId());

        return (string) $crawler->filter(\sprintf('form[action="/speeches/%s/retry"] input[name="_token"]', $failedSpeech->getId()))->attr('value');
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
