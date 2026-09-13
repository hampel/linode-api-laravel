<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Http;

use Closure;
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
 * setting, `Http::globalRequestMiddleware()` and the transport half of `Http::globalOptions()`
 * are all read at the moment of sending, so ordering stops mattering.
 *
 * THE FACTORY IS RESOLVED PER REQUEST TOO, for the same reason one level up. Http::swap(new
 * Factory) - the usual way for a test to start from a clean set of fakes, since fake() merges -
 * binds a NEW factory into the container. A client holding the factory it was constructed with
 * would keep sending through the old one: past the new fakes, past the new factory's
 * preventStrayRequests(), and to the real API with the configured token. So the provider hands
 * in a closure over the container rather than the factory itself.
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
 * THE PENDING REQUEST'S OPTIONS ARE COPIED ACROSS BY HAND, AND ONLY SOME OF THEM. PendingRequest
 * merges its options - the timeouts set here, and everything from Http::globalOptions() - only
 * inside its own sendRequest(), and buildClient() returns a Guzzle client made from the handler
 * stack alone. Sending on that client directly, as this class must, drops every one of them
 * unless they are passed to send(): measured, a configured timeout and connect_timeout arrived as
 * null, and a global proxy and CA bundle likewise.
 *
 * They are passed as an allowlist of TRANSPORT options - how a request is sent, never what is
 * sent. Guzzle applies a headers, query or json option on top of the request it is handed, so
 * passing the options wholesale would let a global Authorization header, query string or body
 * replace what the core package built. See transportOptions().
 *
 * WHAT IT DOES NOT DO: raise ResponseReceived or ConnectionFailed. Laravel dispatches those
 * from PendingRequest::send(), a layer above the handler stack, so anything listening for them
 * - Telescope's HTTP client watcher listens for exactly those two - will not show this
 * traffic. RequestSending DOES fire, because Laravel raises it from a before-sending callback
 * that PendingRequest's constructor registers inside the stack; a listener that counts requests
 * on it and pairs them with responses will count requests that never get one. TransportTest
 * measures all three against Laravel's own send() as a control. The package logs every request
 * through PSR-3 instead, which under Laravel reaches the application log.
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

    /**
     * @param  Factory|Closure(): Factory  $factory  a closure resolving the factory at the moment
     *         of sending, which is what follows Http::swap(). A Factory instance is still
     *         accepted so a hand-built client keeps working, but it is fixed for the life of the
     *         client and a later swap will not reach it.
     */
    public function __construct(
        private readonly Factory|Closure $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Into a local first: a property narrowed by ??= loses that narrowing across the closure
        // call below, as far as static analysis is concerned.
        $handler = $this->handler ??= Utils::chooseHandler();

        $factory = $this->factory instanceof Closure ? ($this->factory)() : $this->factory;

        $pending = $factory->createPendingRequest()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->setHandler($handler);

        return $pending->buildClient()
            ->send($request, [
                // First, so that nothing below can be overridden by a global option - none of
                // those keys is on the allowlist, but the order makes it a property rather than
                // a coincidence.
                ...self::transportOptions($pending->getOptions()),

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

    /**
     * The options from a pending request that govern how a request is sent, never what is sent.
     *
     * Each is taken only when its value has the type Guzzle declares for it; an option that does
     * not is dropped rather than passed on to fail somewhere less obvious. Array forms are rebuilt
     * element by element for the same reason, and because Guzzle 8 declares send()'s options as an
     * array shape that an unchecked array cannot satisfy.
     *
     * @param  array<mixed>  $options
     * @return array{
     *     timeout?: int|float,
     *     connect_timeout?: int|float,
     *     read_timeout?: int|float,
     *     verify?: bool|string,
     *     version?: string|int|float,
     *     force_ip_resolve?: string,
     *     crypto_method?: int,
     *     crypto_method_max?: int,
     *     decode_content?: bool|string,
     *     cert?: string|array{0: string, 1?: string|null},
     *     cert_type?: string,
     *     ssl_key?: string|array{0: string, 1?: string|null},
     *     ssl_key_type?: string,
     *     proxy?: string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null},
     *     curl?: array<int|string, mixed>
     * }
     */
    private static function transportOptions(array $options): array
    {
        $transport = [];

        // One key at a time rather than a loop over names: an array built with a variable key
        // loses its shape to static analysis on the oldest PHPStan this package supports.
        if (self::isNumber($options['timeout'] ?? null)) {
            $transport['timeout'] = $options['timeout'];
        }

        if (self::isNumber($options['connect_timeout'] ?? null)) {
            $transport['connect_timeout'] = $options['connect_timeout'];
        }

        if (self::isNumber($options['read_timeout'] ?? null)) {
            $transport['read_timeout'] = $options['read_timeout'];
        }

        if (isset($options['verify']) && (is_bool($options['verify']) || is_string($options['verify']))) {
            $transport['verify'] = $options['verify'];
        }

        if (isset($options['version']) && (is_string($options['version']) || self::isNumber($options['version']))) {
            $transport['version'] = $options['version'];
        }

        if (isset($options['force_ip_resolve']) && is_string($options['force_ip_resolve'])) {
            $transport['force_ip_resolve'] = $options['force_ip_resolve'];
        }

        if (isset($options['crypto_method']) && is_int($options['crypto_method'])) {
            $transport['crypto_method'] = $options['crypto_method'];
        }

        if (isset($options['crypto_method_max']) && is_int($options['crypto_method_max'])) {
            $transport['crypto_method_max'] = $options['crypto_method_max'];
        }

        if (isset($options['decode_content']) && (is_bool($options['decode_content']) || is_string($options['decode_content']))) {
            $transport['decode_content'] = $options['decode_content'];
        }

        $cert = self::pathWithPassword($options['cert'] ?? null);

        if ($cert !== null) {
            $transport['cert'] = $cert;
        }

        if (isset($options['cert_type']) && is_string($options['cert_type'])) {
            $transport['cert_type'] = $options['cert_type'];
        }

        $sslKey = self::pathWithPassword($options['ssl_key'] ?? null);

        if ($sslKey !== null) {
            $transport['ssl_key'] = $sslKey;
        }

        if (isset($options['ssl_key_type']) && is_string($options['ssl_key_type'])) {
            $transport['ssl_key_type'] = $options['ssl_key_type'];
        }

        $proxy = self::proxy($options['proxy'] ?? null);

        if ($proxy !== null) {
            $transport['proxy'] = $proxy;
        }

        if (isset($options['curl']) && is_array($options['curl'])) {
            $transport['curl'] = $options['curl'];
        }

        return $transport;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * A certificate or key: a path, or a path and its password.
     *
     * @return string|array{0: string, 1?: string|null}|null
     */
    private static function pathWithPassword(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || ! isset($value[0]) || ! is_string($value[0])) {
            return null;
        }

        $password = $value[1] ?? null;

        return is_string($password) ? [$value[0], $password] : [$value[0]];
    }

    /**
     * A proxy: one URI for every scheme, or one per scheme with an exclusion list.
     *
     * @return string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null}|null
     */
    private static function proxy(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $proxy = [];

        if (isset($value['http']) && is_string($value['http'])) {
            $proxy['http'] = $value['http'];
        }

        if (isset($value['https']) && is_string($value['https'])) {
            $proxy['https'] = $value['https'];
        }

        if (isset($value['no'])) {
            if (is_string($value['no'])) {
                $proxy['no'] = $value['no'];
            } elseif (is_array($value['no'])) {
                $proxy['no'] = array_values(array_filter($value['no'], is_string(...)));
            }
        }

        return $proxy === [] ? null : $proxy;
    }
}
