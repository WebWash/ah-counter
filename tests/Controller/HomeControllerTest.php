<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ah Counter');
    }

    public function testTheRecorderUploadsToTheSpeechesEndpointWithACsrfToken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $recorder = $crawler->filter('[data-controller="recorder"]');
        self::assertCount(1, $recorder);
        self::assertSame('/speeches', $recorder->attr('data-recorder-upload-url-value'));
        self::assertNotEmpty($recorder->attr('data-recorder-csrf-token-value'));
        self::assertSelectorExists('[data-controller="recorder"] button[data-action="recorder#start"]');
    }
}
