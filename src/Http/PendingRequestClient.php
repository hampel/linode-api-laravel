<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Http;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that sends through Laravel's HTTP client.
 *
 * This is the whole reason the package exists. hampel/linode-api holds a PSR-18 client of
 * its own, so by default nothing it sends is visible to `Http::fake()` - an application
 * testing against it has to fake at the transport library instead, in a vocabulary its
 * other tests do not use. Handing the client this adapter instead puts every request the
 * package makes on the same handler stack `Http::` builds, so:
 *
 *     Http::fake(['api.linode.com/*' => Http::response(['data' => [...]])]);
 *
 *     $zone = Linode::domains()->findByName('example.com');   // the package's real code path
 *
 *     Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
 *
 * `Http::preventStrayRequests()` works too, and reports the escape as its own
 * StrayRequestException rather than as a transport failure - the package's
 * Connection::dispatch() catches ClientExceptionInterface, and Laravel's exception is a
 * plain RuntimeException, so it passes through with the URL still in the message.
 *
 * REBUILT PER REQUEST, DELIBERATELY. Factory::fake() REPLACES the factory's stub collection
 * rather than adding to it, and createPendingRequest() copies whatever is there at the
 * moment it is called. A pending request built once and kept therefore holds a snapshot: a
 * fake registered after the client was first resolved would never be consulted, and the
 * request would go to the real API. Rebuilding here means the stubs, the stray-request
 * setting, `Http::globalOptions()` and `Http::globalRequestMiddleware()` are all read at the
 * moment of sending, so ordering stops mattering.
 *
 * The Guzzle handler underneath is built once and reused, which is what stops that costing
 * anything: the handler owns curl's connection pool, so keep-alive survives between requests
 * even though the stack around it is new each time. Without it every page of a walk pays a
 * fresh TLS handshake, and Linode pages at 100 - an account with a few hundred zones, or one
 * zone with a few hundred records, is several requests before anything unusual has happened.
 *
 * SENT WITH send() RATHER THAN sendRequest(), which needs explaining because sendRequest() is
 * the PSR-18 method and this class is a PSR-18 client.
 *
 * `laravel_data` and `on_stats` are PendingRequest's contract with the handler stack it
 * builds: PendingRequest::sendRequest() sets both on every call, and the recorder and stub
 * handlers assume they are there. Anything that drives that stack without going through that
 * method - as this class must, because the request it is given is already built by the core
 * package and has to reach Linode byte for byte - has to supply them itself.
 *
 * Laravel 13 reads both defensively. Laravel 12 does not, and without them every request
 * raises "Undefined array key laravel_data" from the recorder and "Undefined array key
 * on_stats" from the stub - which an application with debug error handling turns into an
 * ErrorException, so it is a hard failure rather than a notice in a log. Only the Laravel 12
 * CI job can catch a regression here.
 *
 * The three options beside them reproduce what Guzzle's own sendRequest() sets, so a request
 * sent through here behaves as it would through the plain PSR-18 client the core package is
 * developed against. http_errors in particular must stay off: with it on a 404 would arrive
 * as a Guzzle exception, and the core package would report it as a transport failure instead
 * of mapping it to NotFoundException.
 *
 * WHAT IT DOES NOT DO: Laravel raises its RequestSending and ResponseReceived events from
 * PendingRequest::send(), which is a layer above the handler stack, so those events do not
 * fire and anything listening for them - Telescope's HTTP client watcher - will not show this
 * traffic. The package logs every request through PSR-3 instead, which under Laravel reaches
 * the application log.
 */
final class PendingRequestClient implements ClientInterface
{
    /**
     * Guzzle's default handler, kept so curl can reuse connections. Created on first use
     * rather than in the constructor: a client that is resolved and never sent through - a
     * configured account this request does not touch - should not pay for one.
     *
     * @var callable|null
     */
    private $handler = null;

    public function __construct(
        private readonly Factory $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->handler ??= Utils::chooseHandler();

        return $this->factory->createPendingRequest()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->setHandler($this->handler)
            ->buildClient()
            ->send($request, [
                RequestOptions::SYNCHRONOUS => true,

                // No Linode endpoint answers a redirect, so this settles nothing about this
                // API and is set anyway: it is what Guzzle's PSR-18 entry point hard-codes,
                // and matching it keeps the transport indistinguishable from the one the core
                // package's own suite drives. A 3xx that did appear would be handed back
                // whole rather than followed to somewhere unexamined.
                RequestOptions::ALLOW_REDIRECTS => false,

                RequestOptions::HTTP_ERRORS => false,

                // Left empty rather than filled: Request::data() parses a JSON or form body
                // out of the request itself, so `$request['ttl_sec']` works in an assertion
                // without it. isJson() is a substring test over the Content-Type, so the
                // core package's bare `application/json` satisfies it.
                'laravel_data' => [],

                // Discarded. Laravel's own callback records TransferStats on the
                // PendingRequest, and this one is thrown away with the pending request that
                // built it.
                'on_stats' => static function (TransferStats $stats): void {
                },
            ]);
    }
}
