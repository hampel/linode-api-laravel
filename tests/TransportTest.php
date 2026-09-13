<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Exception\RequestException;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Laravel\Http\PendingRequestClient;
use Hampel\Linode\Api\Laravel\LinodeServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_account(): void
    {
        // The reason the transport is bound under a container key rather than constructed
        // inside the manager: an application with its own outbound HTTP policy - a
        // proxy-aware, SSRF-guarded client everything is required to go through - binds it
        // under linode.http_client and this package uses it, instead of the application
        // writing a second API client.
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

        $this->container()->instance(LinodeServiceProvider::HTTP_CLIENT, $recorder);

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
        //
        // Captured as Throwable and asserted after, rather than caught as ConnectionException.
        // The call goes through the Http facade, and on the lowest toolchain this package
        // supports (PHPStan 2.1.22, Larastan 3.4.2, Laravel 12.61.1) the facade does not carry
        // PendingRequest::get()'s @throws, so a typed catch is reported dead and the assertion
        // after it unreachable. It is also why fail() is not inside the try: a Throwable catch
        // would swallow it.
        $thrown = null;

        try {
            Http::get('https://api.linode.com/v4/domains/1234');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $thrown);
        $this->assertSame(1, $failed);

        try {
            Linode::domains()->get(1234);
            $this->fail('Expected a RequestException.');
        } catch (RequestException) {
        }

        $this->assertSame(1, $failed);
    }

    #[Test]
    public function the_configured_timeouts_reach_the_transport(): void
    {
        // A fake callback receives the options Guzzle is about to use, so what is asserted here
        // is what a real connection would get. PendingRequest merges its options only inside its
        // own sendRequest(), which the adapter does not call - so setting them on the pending
        // request is not the same as sending with them.
        $config = $this->container()->make(Config::class);
        $config->set('linode.timeout', 7);
        $config->set('linode.connect_timeout', 3);

        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(self::zone());
        });

        Linode::domains()->get(1234);

        $this->assertEquals(7, $options['timeout'] ?? null);
        $this->assertEquals(3, $options['connect_timeout'] ?? null);
    }

    #[Test]
    public function global_transport_options_reach_the_transport(): void
    {
        // An application behind a proxy, or trusting its own CA bundle, sets that once with
        // Http::globalOptions() and expects every outbound request to honour it.
        Http::globalOptions([
            'proxy' => 'http://proxy.invalid:3128',
            'verify' => '/etc/ssl/certs/under-test.pem',
        ]);

        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(self::zone());
        });

        Linode::domains()->get(1234);

        $this->assertSame('http://proxy.invalid:3128', $options['proxy'] ?? null);
        $this->assertSame('/etc/ssl/certs/under-test.pem', $options['verify'] ?? null);
    }

    #[Test]
    public function global_options_that_would_rewrite_the_request_do_not_reach_it(): void
    {
        // The reason the options are an allowlist rather than passed wholesale. Guzzle applies a
        // headers, query or json option ON TOP of the request it is handed, so a global one would
        // replace the core package's Authorization and Accept headers, its query string, or its
        // body - and the request would go out as something the core package did not build.
        Http::globalOptions([
            'headers' => ['Authorization' => 'Bearer hijacked', 'X-Global' => 'yes'],
            'query' => ['hijacked' => '1'],
            'json' => ['hijacked' => true],
        ]);

        Http::fake(['api.linode.com/*' => Http::response(self::record())]);

        Linode::records()->update(1234, 55, ['ttl_sec' => 300]);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-under-test')
            && ! $request->hasHeader('X-Global')
            && $request->url() === 'https://api.linode.com/v4/domains/1234/records/55'
            && $request['ttl_sec'] === 300
            && ! isset($request['hijacked']));
    }

    #[Test]
    public function a_client_interface_bound_after_this_provider_does_not_reach_linode(): void
    {
        // Every Laravel API wrapper used to bind the unqualified PSR-18 interface, so in an
        // application with two of them installed the last provider registered supplied the
        // adapter for all of them - its timeouts, its defects, and in general no Http::fake()
        // visibility at all. The adapter is now bound under linode.http_client, and the manager
        // is built only from that.
        $foreign = $this->foreignClient();
        $this->container()->register($this->providerBinding($foreign));

        $this->assertLinodeUsesItsOwnAdapter($foreign);
    }

    #[Test]
    public function a_client_interface_bound_before_this_provider_is_left_alone(): void
    {
        // The other registration order. Linode used to win this one, by overwriting the other
        // binding - which broke whichever package or application had made it. Now neither side
        // touches the other.
        $foreign = $this->foreignClient();
        $this->container()->register($this->providerBinding($foreign));
        $this->container()->register(new LinodeServiceProvider($this->container()), true);

        $this->assertSame($foreign, $this->container()->make(ClientInterface::class));
        $this->assertLinodeUsesItsOwnAdapter($foreign);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function siblingOrders(): array
    {
        return [
            'sibling registered after this provider' => [false],
            'sibling registered before this provider' => [true],
        ];
    }

    #[Test]
    #[DataProvider('siblingOrders')]
    public function a_sibling_adapter_that_is_also_faked_does_not_lend_its_timeout(bool $siblingFirst): void
    {
        // The realistic sibling, which the recorder stubs above are not. Another Laravel API
        // wrapper's adapter is a PendingRequestClient too, resolving the same faked factory, so
        // Http::fake() intercepts whichever adapter sends and the response looks right either way.
        // Only the timeout tells them apart - which is how the shared-binding defect showed up in
        // an application with three wrappers: one package's calls bounded by another's timeout.
        //
        // The sibling resolves the application's own factory, as a real sibling's provider does, so
        // a regression reaches the fake rather than the network; the .invalid host is a second
        // guard in case it ever does not.
        $sibling = new class ($this->container()) extends ServiceProvider {
            public function register(): void
            {
                $app = $this->app;

                $this->app->singleton(ClientInterface::class, static fn (): ClientInterface => new PendingRequestClient(
                    static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                    3.0,
                    1.0,
                ));
            }
        };

        $this->container()->register($sibling);

        if ($siblingFirst) {
            $this->container()->register(new LinodeServiceProvider($this->container()), true);
        }

        $config = $this->container()->make(Config::class);
        $config->set('linode.timeout', 7);
        $config->set('linode.base_uri', 'https://api.linode.invalid');

        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(self::zone());
        });

        $this->assertSame('example.com', Linode::domains()->get(1234)->domain);
        $this->assertIsArray($options);
        $this->assertEquals(7, $options['timeout'] ?? null, "the Linode request carried the sibling's timeout");
    }

    private function assertLinodeUsesItsOwnAdapter(object $foreign): void
    {
        $this->container()->make(Config::class)->set('linode.timeout', 7);

        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(self::zone());
        });

        Linode::domains()->get(1234);

        $this->assertSame(0, $foreign->sent ?? null, 'the foreign ClientInterface sent the Linode request');
        $this->assertIsArray($options, 'Http::fake() did not see the Linode request');
        $this->assertEquals(7, $options['timeout'] ?? null);
    }

    private function foreignClient(): object
    {
        return new class () implements ClientInterface {
            public int $sent = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent++;

                return new \GuzzleHttp\Psr7\Response(200, [], '{"id":1,"domain":"foreign.example.com","type":"master"}');
            }
        };
    }

    private function providerBinding(object $client): ServiceProvider
    {
        // Standing in for a sibling wrapper, or an unrelated library, that binds the interface.
        return new class ($this->container(), $client) extends ServiceProvider {
            public function __construct(\Illuminate\Contracts\Foundation\Application $app, private readonly object $client)
            {
                parent::__construct($app);
            }

            public function register(): void
            {
                $client = $this->client;
                $this->app->singleton(ClientInterface::class, static fn (): object => $client);
            }
        };
    }
}
