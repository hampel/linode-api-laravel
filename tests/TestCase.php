<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Laravel\LinodeServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel
 * version under test comes from Composer resolution rather than from an installed framework.
 * Never install a framework to test a package.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LinodeServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Linode' => Linode::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('linode.default', 'main');
        $app['config']->set('linode.accounts', [
            'main' => ['token' => 'token-under-test'],
            'reseller' => ['token' => 'reseller-token'],
        ]);
        $app['config']->set('linode.version', 'v4');
        $app['config']->set('linode.base_uri', null);
        $app['config']->set('linode.page_size', null);
    }

    /**
     * A zone as Linode answers with one.
     *
     * Kept here rather than in each test because the envelope is what the core package parses
     * and a hand-trimmed copy per test is how a fixture stops resembling the API.
     *
     * @return array<string, mixed>
     */
    protected static function zone(int $id = 1234, string $domain = 'example.com'): array
    {
        return [
            'id' => $id,
            'domain' => $domain,
            'type' => 'master',
            'status' => 'active',
            'soa_email' => 'hostmaster@example.com',
            'description' => '',
            'refresh_sec' => 0,
            'retry_sec' => 0,
            'expire_sec' => 0,
            'ttl_sec' => 300,
            'master_ips' => [],
            'axfr_ips' => [],
            'tags' => [],
        ];
    }

    /**
     * A record as Linode answers with one.
     *
     * @return array<string, mixed>
     */
    protected static function record(int $id = 55, string $name = 'www', string $type = 'A'): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'target' => '203.0.113.10',
            'priority' => 0,
            'weight' => 0,
            'port' => 0,
            'service' => null,
            'protocol' => null,
            'ttl_sec' => 300,
            'tag' => null,
        ];
    }

    /**
     * The collection envelope every list endpoint on this API answers in.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected static function collection(array $items, int $page = 1, int $pages = 1, ?int $results = null): array
    {
        return [
            'data' => $items,
            'page' => $page,
            'pages' => $pages,
            'results' => $results ?? count($items),
        ];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use
     * of it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the
        // app boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while
        // running tests - so phpunit.xml's failOnDeprecation never sees one and is inert in
        // any Testbench-based package. This throws on them instead, which is the whole point
        // of the flag: a library should hear about a deprecation before its users do.
        $this->withoutDeprecationHandling();
    }
}
