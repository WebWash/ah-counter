<?php

declare(strict_types=1);

namespace App\Tests\AccuracyCheck;

use App\AccuracyCheck\AccuracyRecording;
use App\AccuracyCheck\DeepgramAccuracyTranscriber;
use App\Processing\PermanentFailure;
use App\Transcription\DeepgramResponseNormaliser;
use App\Transcription\DeepgramTranscriber;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Deepgram\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeepgramAccuracyTranscriberTest extends TestCase
{
    private const array LISTEN_RESPONSE = [
        'metadata' => ['duration' => 3.2],
        'results' => ['channels' => [['alternatives' => [[
            'transcript' => 'Um, so I uh think.',
            'words' => [
                ['word' => 'um', 'punctuated_word' => 'Um,', 'start' => 0.1, 'end' => 0.4, 'confidence' => 0.9],
                ['word' => 'so', 'punctuated_word' => 'so', 'start' => 0.5, 'end' => 0.7, 'confidence' => 0.9],
                ['word' => 'i', 'punctuated_word' => 'I', 'start' => 0.8, 'end' => 0.9, 'confidence' => 0.9],
                ['word' => 'uh', 'punctuated_word' => 'uh', 'start' => 1.0, 'end' => 1.3, 'confidence' => 0.8],
                ['word' => 'think', 'punctuated_word' => 'think.', 'start' => 1.4, 'end' => 1.8, 'confidence' => 0.9],
            ],
        ]]]]],
    ];

    /** @var list<array{method: string, url: string, headers: list<string>}> */
    private array $requests = [];

    private string $audioPath;

    protected function setUp(): void
    {
        $this->audioPath = (string) tempnam(sys_get_temp_dir(), 'clip');
        file_put_contents($this->audioPath, 'fake webm bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->audioPath);
    }

    public function testKeepsFillerWordsAndReadsEveryWordFromTheRawResponse(): void
    {
        $transcription = $this->transcriber(new JsonMockResponse(self::LISTEN_RESPONSE))->transcribe($this->recording());

        self::assertSame(['um', 'so', 'i', 'uh', 'think'], $transcription->words);
        self::assertSame('Um, so I uh think.', $transcription->text, 'the punctuated words, joined');
        self::assertSame(self::LISTEN_RESPONSE, $transcription->response);

        $listen = $this->listenRequest();
        self::assertSame('POST', $listen['method']);
        parse_str((string) parse_url($listen['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['model' => 'nova-2', 'filler_words' => 'true', 'punctuate' => 'true', 'language' => 'en'], $query);
        self::assertContains('Content-Type: audio/webm', $listen['headers']);
    }

    public function testPassesExtraProviderOptionsSuchAsSmartFormat(): void
    {
        $this->transcriber(new JsonMockResponse(self::LISTEN_RESPONSE))->transcribe($this->recording(), ['smart_format' => 'true']);

        parse_str((string) parse_url($this->listenRequest()['url'], \PHP_URL_QUERY), $query);
        self::assertSame('true', $query['smart_format']);
        self::assertSame('true', $query['filler_words']);
    }

    public function testSurfacesAnHttpErrorAsAFailureRatherThanMissingWords(): void
    {
        $unauthorised = new JsonMockResponse(['err_code' => 'INVALID_AUTH', 'err_msg' => 'Invalid credentials.'], ['http_code' => 401]);

        $this->expectException(PermanentFailure::class);

        $this->transcriber($unauthorised)->transcribe($this->recording());
    }

    private function transcriber(MockResponse $listenResponse): DeepgramAccuracyTranscriber
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($listenResponse): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return str_contains($url, '/models')
                ? new JsonMockResponse(['stt' => [['name' => 'nova-2', 'canonical_name' => 'nova-2']], 'tts' => []])
                : $listenResponse;
        });

        $normaliser = new DeepgramResponseNormaliser();

        return new DeepgramAccuracyTranscriber(new DeepgramTranscriber(Factory::createPlatform(apiKey: 'test-key', httpClient: $http), $normaliser, 'nova-2'), $normaliser);
    }

    private function recording(): AccuracyRecording
    {
        return new AccuracyRecording('clip-1', $this->audioPath, 'audio/webm', ['um' => 1]);
    }

    /**
     * @return array{method: string, url: string, headers: list<string>}
     */
    private function listenRequest(): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], '/listen')) {
                return $request;
            }
        }

        self::fail('No /listen request was sent.');
    }
}
