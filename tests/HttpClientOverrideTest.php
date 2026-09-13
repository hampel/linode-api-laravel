<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Laravel\LinodeServiceProvider;
use Hampel\Linode\Api\Laravel\Tests\Fixture\HttpClientOverrideProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of linode.http_client survives this package's provider registering
 * after it.
 *
 * A full Laravel application registers discovered package providers before its own, so an
 * override in AppServiceProvider comes last and would win whatever the package did. Laravel Zero
 * runs no discovery and registers config/app.php in list order - and the README's Laravel Zero
 * section lists AppServiceProvider first. With singleton(), the package's binding replaced the
 * application's there, silently. singletonIf() keeps whichever came first.
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HttpClientOverrideProvider::class, LinodeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // If the override were lost, the package's own adapter would send this request. A
        // reserved .invalid host and preventStrayRequests() below mean that fails here rather
        // than reaching Linode with the suite's token.
        $app['config']->set('linode.base_uri', 'https://api.linode.invalid');
    }

    #[Test]
    public function an_override_registered_before_this_provider_is_the_transport_used(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);

        $this->assertSame('override.example.com', Linode::domains()->get(1234)->domain);
        $this->assertSame(['https://api.linode.invalid/v4/domains/1234'], HttpClientOverrideProvider::$client?->sent);
    }

    protected function tearDown(): void
    {
        HttpClientOverrideProvider::$client = null;

        parent::tearDown();
    }
}
