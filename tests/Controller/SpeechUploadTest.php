<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Message\ProcessSpeech;
use App\Repository\SpeechRepository;
use App\Tests\RecreatesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class SpeechUploadTest extends WebTestCase
{
    use RecreatesDatabase;

    private KernelBrowser $client;
    private string $recordingsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::recreateDatabase();
        $this->recordingsDir = self::getContainer()->getParameter('app.recordings_dir');
        (new Filesystem())->remove($this->recordingsDir);
    }

    public function testAValidUploadCreatesAPendingSpeechStoresTheRecordingAndDispatchesProcessing(): void
    {
        $this->upload($this->recording('tone.webm', 'audio/webm;codecs=opus'), ['clientDuration' => '42.5', 'title' => '  Club speech  ']);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        $speech = self::getContainer()->get(SpeechRepository::class)->find($body['id']);
        self::assertInstanceOf(Speech::class, $speech);
        self::assertSame('/speeches/'.$body['id'], $body['url']);
        self::assertSame(SpeechStatus::Pending, $speech->getStatus());
        self::assertSame('Club speech', $speech->getTitle());
        self::assertSame(42.5, $speech->getDurationSeconds());
        self::assertSame('audio/webm', $speech->getRecordingMimeType());
        self::assertFileExists($this->recordingsDir.'/'.$body['id'].'.webm');

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ProcessSpeech::class, $message);
        self::assertSame($body['id'], $message->speechId);
    }

    public function testABlankTitleIsStoredAsNoTitle(): void
    {
        $this->upload($this->recording('tone.mp4', 'audio/mp4'), ['clientDuration' => '30', 'title' => '   ']);

        self::assertResponseStatusCodeSame(201);
        $speech = self::getContainer()->get(SpeechRepository::class)->find($this->json()['id']);
        self::assertNull($speech?->getTitle());
        self::assertSame('audio/mp4', $speech->getRecordingMimeType());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function acceptedDurations(): iterable
    {
        yield 'shortest allowed' => [9.0];
        yield 'longest allowed' => [301.0];
    }

    #[DataProvider('acceptedDurations')]
    public function testDurationsOnTheEdgeOfTheRuleAreAccepted(float $duration): void
    {
        $this->upload($this->recording('tone.webm', 'audio/webm'), ['clientDuration' => (string) $duration]);

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string}>
     */
    public static function rejectedUploads(): iterable
    {
        yield 'too short' => ['tone.webm', 'audio/webm', ['clientDuration' => '8.9'], 'between 10 seconds and 5 minutes'];
        yield 'too long' => ['tone.webm', 'audio/webm', ['clientDuration' => '301.1'], 'between 10 seconds and 5 minutes'];
        yield 'duration missing' => ['tone.webm', 'audio/webm', [], 'length of the Recording is missing'];
        yield 'duration not a number' => ['tone.webm', 'audio/webm', ['clientDuration' => 'long'], 'float'];
        yield 'not audio' => ['not-audio.txt', 'text/plain', ['clientDuration' => '30'], 'not a supported audio file'];
        yield 'webm sent as mp4' => ['tone.webm', 'audio/mp4', ['clientDuration' => '30'], 'not a supported audio file'];
        yield 'title too long' => ['tone.webm', 'audio/webm', ['clientDuration' => '30', 'title' => str_repeat('a', 121)], '120 characters'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('rejectedUploads')]
    public function testAnInvalidUploadIsRejectedWithAMessage(string $fixture, string $browserType, array $fields, string $message): void
    {
        $this->upload($this->recording($fixture, $browserType), $fields);

        $this->assertRejected($message);
    }

    public function testARecordingOverTheSizeLimitIsRejected(): void
    {
        $recording = $this->recording('tone.webm', 'audio/webm');
        $handle = fopen($recording->getPathname(), 'r+');
        ftruncate($handle, 26 * 1024 * 1024);
        fclose($handle);

        $this->upload($recording, ['clientDuration' => '30']);

        $this->assertRejected('too large');
    }

    public function testPhpRejectingTheFileForItsSizeGetsAClearMessage(): void
    {
        $recording = new UploadedFile($this->copyOf('tone.webm'), 'speech.webm', 'audio/webm', \UPLOAD_ERR_INI_SIZE, true);

        $this->upload($recording, ['clientDuration' => '30']);

        $this->assertRejected('too large');
    }

    public function testAnEmptyBodyCausedByPostMaxSizeGetsAClearMessage(): void
    {
        $this->client->request('POST', '/speeches', server: [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----x',
            'CONTENT_LENGTH' => (string) (40 * 1024 * 1024),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertRejected('too large');
    }

    public function testAMissingRecordingIsRejected(): void
    {
        $this->client->request('POST', '/speeches', ['clientDuration' => '30', '_token' => $this->csrfToken()], server: ['HTTP_ACCEPT' => 'application/json']);

        $this->assertRejected('attach a Recording');
    }

    public function testABadCsrfTokenIsRejected(): void
    {
        $this->upload($this->recording('tone.webm', 'audio/webm'), ['clientDuration' => '30', '_token' => 'forged']);

        $this->assertRejected('refresh the page');
    }

    public function testTheRecordingIsStreamedWithItsStoredTypeAndSupportsRange(): void
    {
        $this->upload($this->recording('tone.webm', 'audio/webm;codecs=opus'), ['clientDuration' => '30']);
        $url = $this->json()['url'].'/recording';

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'audio/webm');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');

        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 0-99/'.filesize(__DIR__.'/../Fixtures/audio/tone.webm'));
    }

    public function testDeletingADoneSpeechRemovesTheRowAndTheFile(): void
    {
        $id = $this->uploadAndMove(static fn (Speech $s) => $s->markDone());

        $this->client->request('POST', '/speeches/'.$id.'/delete', ['_token' => $this->deleteToken($id)]);

        self::assertResponseRedirects('/');
        self::assertNull(self::getContainer()->get(SpeechRepository::class)->find($id));
        self::assertFileDoesNotExist($this->recordingsDir.'/'.$id.'.webm');
    }

    public function testDeletingAFailedSpeechIsAllowed(): void
    {
        $id = $this->uploadAndMove(static fn (Speech $s) => $s->markFailed('Deepgram is down.'));

        $this->client->request('POST', '/speeches/'.$id.'/delete', ['_token' => $this->deleteToken($id)]);

        self::assertResponseRedirects('/');
    }

    public function testDeletingASpeechThatIsStillProcessingIsAConflict(): void
    {
        $id = $this->uploadAndMove(static fn (Speech $s) => $s->startStep(SpeechStatus::Transcribing));

        $token = $this->deleteToken($this->uploadAndMove(static fn (Speech $s) => $s->markDone()));

        $this->client->request('POST', '/speeches/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(409);
        self::assertNotNull(self::getContainer()->get(SpeechRepository::class)->find($id));
        self::assertFileExists($this->recordingsDir.'/'.$id.'.webm');
    }

    public function testDeletingWithABadCsrfTokenIsRefused(): void
    {
        $id = $this->uploadAndMove(static fn (Speech $s) => $s->markDone());

        $this->client->request('POST', '/speeches/'.$id.'/delete', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::getContainer()->get(SpeechRepository::class)->find($id));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownSpeechUrls(): iterable
    {
        $unknown = Uuid::v4()->toRfc4122();

        yield 'unknown id, page' => ['GET', '/speeches/'.$unknown];
        yield 'unknown id, recording' => ['GET', '/speeches/'.$unknown.'/recording'];
        yield 'unknown id, delete' => ['POST', '/speeches/'.$unknown.'/delete'];
        yield 'not a uuid, page' => ['GET', '/speeches/not-a-uuid'];
        yield 'not a uuid, recording' => ['GET', '/speeches/123/recording'];
    }

    #[DataProvider('unknownSpeechUrls')]
    public function testAnUnknownOrInvalidIdIsNotFound(string $method, string $url): void
    {
        $this->client->request($method, $url);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Uploads a Recording, then moves the new Speech on as a worker would.
     *
     * @param callable(Speech): void $move
     */
    private function uploadAndMove(callable $move): string
    {
        $this->upload($this->recording('tone.webm', 'audio/webm'), ['clientDuration' => '30']);
        $id = $this->json()['id'];

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $speech = $entityManager->find(Speech::class, Uuid::fromString($id));
        $move($speech);
        $entityManager->flush();

        return $id;
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(UploadedFile $recording, array $fields): void
    {
        $fields += ['_token' => $this->csrfToken()];

        $this->client->request('POST', '/speeches', $fields, ['recording' => $recording], ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * Reads the upload token from the recorder on the home page, as the browser does.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/');

        return (string) $crawler->filter('[data-recorder-csrf-token-value]')->attr('data-recorder-csrf-token-value');
    }

    /**
     * Reads the delete token from the delete form on a finished Speech's page.
     */
    private function deleteToken(string $finishedSpeechId): string
    {
        $crawler = $this->client->request('GET', '/speeches/'.$finishedSpeechId);

        return (string) $crawler->filter(\sprintf('form[action="/speeches/%s/delete"] input[name="_token"]', $finishedSpeechId))->attr('value');
    }

    private function assertRejected(string $message): void
    {
        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        $messages = implode(' ', array_column($body['violations'] ?? [], 'title')).' '.($body['detail'] ?? '');
        self::assertStringContainsStringIgnoringCase($message, $messages);
        self::assertDirectoryDoesNotExist($this->recordingsDir);
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function recording(string $fixture, string $browserType): UploadedFile
    {
        return new UploadedFile($this->copyOf($fixture), 'speech.bin', $browserType, null, true);
    }

    private function copyOf(string $fixture): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'ah-upload-');
        copy(__DIR__.'/../Fixtures/audio/'.$fixture, $copy);

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
