<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Exception\RequestException;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_account(): void
    {
        // The reason ClientInterface is bound by interface rather than constructed inside the
        // manager: an application with its own outbound HTTP policy - a proxy-aware,
        // SSRF-guarded client everything is required to go through - binds it here and this
        // package uses it, instead of the application writing a second API client.
        $recorder = new class () implements ClientInterface {
            /** @var list<string> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = (string) $request->getUri();

                return new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"id":1,"domain":"example.com","type":"master"}'
                );
            }
        };

        $this->container()->instance(ClientInterface::class, $recorder);

        Linode::domains()->get(1);
        Linode::client('reseller')->domains()->get(2);

        $this->assertSame([
            'https://api.linode.com/v4/domains/1',
            'https://api.linode.com/v4/domains/2',
        ], $recorder->sent);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        // Rebuilding the pending request per send is what buys this: an application's own Http::
        // configuration applies to the package's traffic without the package knowing anything
        // about it.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        Http::fake(['api.linode.com/*' => Http::response(self::zone())]);

        Linode::domains()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // The setting exists for a recorded fixture served locally and for a proxy that
        // terminates the connection. Asserted through the transport rather than only on the
        // Config object, because a base URI that reached Config and not the wire would look
        // configured and change nothing.
        $this->container()->make(Config::class)->set('linode.base_uri', 'http://localhost:8080');

        Http::fake(['localhost:8080/*' => Http::response(self::zone())]);

        Linode::domains()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'http://localhost:8080/v4/domains/1234');
    }

    #[Test]
    public function the_configured_page_size_is_asked_for_on_every_list(): void
    {
        $this->container()->make(Config::class)->set('linode.page_size', 25);

        Http::fake(['api.linode.com/*' => Http::response(self::collection([self::zone()]))]);

        Linode::domains()->list();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page_size=25'));
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // Half of Laravel's HTTP client events reach this traffic, and which half matters to
        // anyone listening. PendingRequest's constructor registers a before-sending callback
        // that dispatches RequestSending, and that callback runs inside the handler stack
        // PendingRequestClient drives - so it fires. ResponseReceived is dispatched from
        // PendingRequest::send(), a layer above the stack, which the adapter never calls - so
        // it does not. A listener pairing the two counts requests that never get a response.
        //
        // Two requests rather than one, so a count of 1 cannot be an event fired once when the
        // client was resolved rather than per request.
        $sending = 0;
        $received = 0;
        Event::listen(RequestSending::class, function () use (&$sending): void {
            $sending++;
        });
        Event::listen(ResponseReceived::class, function () use (&$received): void {
            $received++;
        });

        Http::fake(['api.linode.com/*' => Http::response(self::zone())]);

        // The control. Through Laravel's own PendingRequest::send() both events fire, so a zero
        // below means the adapter's path skipped the event - not that the listener was never
        // wired to the dispatcher the HTTP factory uses.
        Http::get('https://api.linode.com/v4/domains/1234');
        $this->assertSame([1, 1], [$sending, $received]);

        Linode::domains()->get(1234);
        Linode::domains()->get(1234);

        $this->assertSame(3, $sending);
        $this->assertSame(1, $received);
    }

    #[Test]
    public function connection_failed_does_not_fire_either(): void
    {
        // The other event Telescope's HTTP client watcher listens for, alongside
        // ResponseReceived. Laravel raises it from PendingRequest::send() too, so a failed
        // connection is invisible to Telescope as well - and arrives as the core package's own
        // RequestException, which is what the core's PSR-3 logging records.
        $failed = 0;
        Event::listen(ConnectionFailed::class, function () use (&$failed): void {
            $failed++;
        });

        Http::fake(['api.linode.com/*' => Http::failedConnection()]);

        // The control, as above: Laravel's own send() path does raise it.
        try {
            Http::get('https://api.linode.com/v4/domains/1234');
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException) {
        }
        $this->assertSame(1, $failed);

        try {
            Linode::domains()->get(1234);
            $this->fail('Expected a RequestException.');
        } catch (RequestException) {
        }

        $this->assertSame(1, $failed);
    }
}
