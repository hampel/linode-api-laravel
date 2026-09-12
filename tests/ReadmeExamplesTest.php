<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Entity\Domain;
use Hampel\Linode\Api\Entity\DomainRecord;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Laravel\LinodeManager;
use Hampel\Linode\Api\Laravel\Tests\Fixture\Instances;
use Hampel\Linode\Api\Result\TokenStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching
 * what the package does. These are the ones with a signature in them, so a renamed accessor or
 * a changed return type fails here rather than being discovered by somebody following the
 * documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_token_check_answers_what_the_readme_says_it_does(): void
    {
        Http::fake([
            'api.linode.com/v4/profile' => Http::response(
                ['username' => 'exampleuser', 'email' => 'user@example.com', 'restricted' => false],
                200,
                ['X-OAuth-Scopes' => 'domains:read_write']
            ),
        ]);

        $status = Linode::verify();

        $this->assertInstanceOf(TokenStatus::class, $status);
        $this->assertSame('exampleuser', $status->username());
        $this->assertFalse($status->isRestricted());
        $this->assertTrue($status->allows('domains:read_write'));
        $this->assertSame([], $status->missing(['domains:read_write']));

        // The summary is what goes in a startup log, so it must not carry the token.
        $this->assertStringNotContainsString('token-under-test', $status->summary());
    }

    #[Test]
    public function the_usage_example_finds_a_zone_and_writes_a_record(): void
    {
        Http::fake([
            'api.linode.com/v4/domains?*' => Http::response(self::collection([self::zone()])),
            'api.linode.com/v4/domains/1234/records' => Http::response(self::record()),
        ]);

        $zone = Linode::domains()->findByName('example.com');

        $this->assertInstanceOf(Domain::class, $zone);

        $record = Linode::domains()->records($zone)->create(
            DomainRecord::a('www', '203.0.113.10')->withTtl(300)
        );

        $this->assertInstanceOf(DomainRecord::class, $record);
        $this->assertSame('www', $record->name);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.linode.com/v4/domains/1234/records'
            && $request['type'] === 'A'
            && $request['target'] === '203.0.113.10'
            && $request['ttl_sec'] === 300);
    }

    #[Test]
    public function a_named_account_is_reached_the_way_the_readme_says(): void
    {
        Http::fake(['api.linode.com/*' => Http::response(self::collection([self::zone()]))]);

        $zones = Linode::client('reseller')->domains()->all();

        $this->assertCount(1, $zones);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer reseller-token'
        ));
    }

    #[Test]
    public function the_manager_can_be_injected_instead_of_using_the_facade(): void
    {
        Http::fake(['api.linode.com/*' => Http::response(self::collection([self::zone()]))]);

        $linode = $this->container()->make(LinodeManager::class);

        $this->assertCount(1, $linode->client('reseller')->domains()->all());
    }

    #[Test]
    public function an_entity_handed_to_a_json_response_serialises_to_linodes_payload(): void
    {
        // The README's `return response()->json(Linode::domains()->get($id));`. This asserts
        // Laravel's JSON response path honours the entity's \JsonSerializable, not that the
        // core package serialises correctly - that is covered in the core package's own suite
        // and duplicating it would be this package testing that one.
        $payload = self::zone() + ['a_field_this_release_has_never_heard_of' => 'kept anyway'];

        Http::fake(['api.linode.com/*' => Http::response($payload)]);

        $response = new JsonResponse(Linode::domains()->get(1234));
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(1234, $decoded['id'] ?? null);
        $this->assertSame('example.com', $decoded['domain'] ?? null);
        $this->assertSame('kept anyway', $decoded['a_field_this_release_has_never_heard_of'] ?? null);
    }

    #[Test]
    public function the_testing_section_snippet_works_with_the_fixture_it_shows(): void
    {
        // The README's testing example, byte for byte in its payload. A trimmed fixture is
        // better documentation than a full one and only if it actually parses - three keys and
        // the envelope is the minimum the core package needs to build a Domain.
        Http::preventStrayRequests();

        Http::fake([
            'api.linode.com/*' => Http::response([
                'data' => [['id' => 1234, 'domain' => 'example.com', 'type' => 'master']],
                'page' => 1, 'pages' => 1, 'results' => 1,
            ]),
        ]);

        $zone = Linode::domains()->findByName('example.com');

        $this->assertInstanceOf(Domain::class, $zone);
        $this->assertSame(1234, $zone->id);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
    }

    #[Test]
    public function an_endpoint_the_core_does_not_wrap_is_reachable_through_the_facade(): void
    {
        // The extension point, through the container. Most of this API is not wrapped, so an
        // application needing one of the other endpoints writes an Endpoint subclass - and it
        // has to arrive over this package's transport, or every test of it escapes the fakes.
        Http::fake([
            'api.linode.com/*' => Http::response(self::collection([['id' => 7, 'label' => 'web-1']])),
        ]);

        $instances = Linode::client()->endpoint(Instances::class)->all();

        $this->assertSame([['id' => 7, 'label' => 'web-1']], $instances);
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://api.linode.com/v4/linode/instances'
        ));
    }
}
