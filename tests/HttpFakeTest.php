<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reason the package exists.
 *
 * hampel/linode-api holds its own PSR-18 client, so by default nothing it sends is visible to
 * Http::fake() and an application testing against it has to fake at the transport library
 * instead - in a vocabulary the rest of its suite does not use. Everything below goes through
 * the package's real request building, filtering, status mapping and exception hierarchy; only
 * the socket is replaced.
 *
 * If this file does not pass, the package has no reason to exist.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        Http::fake([
            'api.linode.com/*' => Http::response(self::collection([self::zone()])),
        ]);

        $zone = Linode::domains()->findByName('example.com');

        $this->assertInstanceOf(Domain::class, $zone);
        $this->assertSame(1234, $zone->id);
        $this->assertSame('example.com', $zone->domain);
    }

    #[Test]
    public function the_request_is_recorded_for_assertion(): void
    {
        Http::fake([
            'api.linode.com/*' => Http::response(self::zone()),
        ]);

        Linode::domains()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.linode.com/v4/domains/1234'
            && $request->hasHeader('Authorization', 'Bearer token-under-test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function the_filter_header_is_visible_to_an_assertion(): void
    {
        // Worth its own test because filtering on this API is a request HEADER rather than a
        // query string. An application asserting that it looked a zone up by name has nothing
        // in the URL to match on - X-Filter is the whole evidence, and it only reaches the
        // assertion because the core package builds the request and this transport sends it
        // unaltered.
        Http::fake([
            'api.linode.com/*' => Http::response(self::collection([self::zone()])),
        ]);

        Linode::domains()->findByName('example.com');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'X-Filter',
            '{"domain":"example.com"}'
        ));
    }

    #[Test]
    public function a_json_body_is_readable_by_the_assertion(): void
    {
        // Illuminate\Http\Client\Request::isJson() is a substring test over the Content-Type,
        // which the core package's bare `application/json` satisfies - so data() parses the
        // body and $request['ttl_sec'] works. Assert on the parsed body rather than on the
        // encoded string: key order is not a promise anyone should depend on.
        Http::fake([
            'api.linode.com/*' => Http::response(self::record()),
        ]);

        Linode::records()->update(1234, 55, ['ttl_sec' => 300]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->isJson()
            && $request['ttl_sec'] === 300);
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the factory's
        // stub collection, and createPendingRequest() copies whatever is there when it is
        // called - so a Guzzle client built at resolution time holds a snapshot taken before
        // these stubs existed, and the request would go to the real API. PendingRequestClient
        // rebuilds per send, so it does not.
        $client = Linode::client();

        Http::preventStrayRequests();
        Http::fake([
            'api.linode.com/*' => Http::response(self::zone(4321, 'late.example.com')),
        ]);

        $this->assertSame('late.example.com', $client->domains()->get(4321)->domain);
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so
        // it arrives with Laravel's own message and the URL still in it.
        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api.linode.com/v4/domains/1234');

        Linode::domains()->get(1234);
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response - telling a zone that is not
        // there from a token that cannot see it is the whole reason the core package exists.
        Http::fake([
            'api.linode.com/*' => Http::response(['errors' => [['reason' => 'Not found']]], 404),
        ]);

        $this->expectException(NotFoundException::class);

        Linode::domains()->get(1234);
    }

    #[Test]
    public function the_rate_limit_headers_survive_the_trip(): void
    {
        // ResponseMeta is read off the response headers, so an integration that slows itself
        // down from `remaining` rather than waiting for a 429 depends on this transport
        // handing the headers back. A fake is the only place that can be asserted.
        Http::fake([
            'api.linode.com/*' => Http::response(self::zone(), 200, [
                'X-RateLimit-Limit' => '1600',
                'X-RateLimit-Remaining' => '1599',
                'X-RateLimit-Reset' => '1757721600',
                'X-OAuth-Scopes' => 'domains:read_write',
            ]),
        ]);

        $response = Linode::connection()->get('domains/1234');

        $this->assertSame(1600, $response->meta->rateLimit);
        $this->assertSame(1599, $response->meta->rateLimitRemaining);
        $this->assertTrue($response->meta->scopes->allows('domains:read_only'));
    }

    #[Test]
    public function a_derived_client_sends_through_the_same_faked_transport(): void
    {
        // withCredential() builds a new Client over the connection's existing transport. If it
        // did not, an application resolving a per-tenant token at request time would fake the
        // first call and reach the real API on every one after it.
        Http::fake([
            'api.linode.com/*' => Http::response(self::zone(9, 'tenant.example.com')),
        ]);

        $zone = Linode::withCredential(new AccessToken('tenant-token'))->domains()->get(9);

        $this->assertSame('tenant.example.com', $zone->domain);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer tenant-token'
        ));
    }

    #[Test]
    public function a_beta_client_sends_through_the_same_faked_transport(): void
    {
        // withVersion() is the same property one level up: the version is a URL segment, so a
        // second client is the only way to reach v4beta, and it has to carry this transport
        // with it or beta traffic escapes every fake in the suite.
        Http::fake([
            'api.linode.com/*' => Http::response(['data' => []]),
        ]);

        Linode::withVersion('v4beta')->connection()->get('object-storage/quotas');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.linode.com/v4beta/object-storage/quotas');
    }
}
