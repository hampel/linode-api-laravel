<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records what it was asked to send and answers with one zone.
 */
final class RecordingPsr18Client implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();

        return new Response(200, ['Content-Type' => 'application/json'], '{"id":1234,"domain":"override.example.com","type":"master"}');
    }
}
