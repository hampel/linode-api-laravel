<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Laravel\Http\PendingRequestClient;
use Hampel\Linode\Api\Laravel\LinodeManager;
use Hampel\Linode\Api\Laravel\LinodeServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function the_package_config_is_merged_into_the_application(): void
    {
        $config = $this->container()->make(Config::class);

        foreach (['default', 'accounts', 'version', 'page_size', 'base_uri', 'timeout', 'connect_timeout'] as $key) {
            $this->assertTrue($config->has('linode.' . $key), sprintf('linode.%s is not merged', $key));
        }
    }

    #[Test]
    public function the_config_file_is_publishable_under_its_own_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(LinodeServiceProvider::class, 'linode-config');

        $this->assertSame([config_path('linode.php')], array_values($published));
    }

    #[Test]
    public function the_shipped_config_names_one_account_and_no_credential(): void
    {
        // Read straight from the file rather than the merged config, which the test case has
        // already overridden. An unset environment must leave the account unusable rather than
        // pointing somewhere with something.
        $defaults = require __DIR__ . '/../config/linode.php';
        $this->assertIsArray($defaults);

        $accounts = $defaults['accounts'] ?? null;
        $this->assertIsArray($accounts);

        $this->assertSame('main', $defaults['default'] ?? null);
        $this->assertSame(['main'], array_keys($accounts));
        $this->assertSame(['token' => null], $accounts['main'] ?? null);
        $this->assertSame('v4', $defaults['version'] ?? null);
        // array_key_exists rather than ??, which cannot tell an absent key from a null one -
        // and null is the value under test here.
        $this->assertArrayHasKey('page_size', $defaults);
        $this->assertNull($defaults['page_size']);
        $this->assertArrayHasKey('base_uri', $defaults);
        $this->assertNull($defaults['base_uri']);
        $this->assertSame(10, $defaults['timeout'] ?? null);
        $this->assertSame(5, $defaults['connect_timeout'] ?? null);
    }

    #[Test]
    public function the_transport_is_bound_by_interface_so_it_can_be_replaced(): void
    {
        $this->assertInstanceOf(PendingRequestClient::class, $this->container()->make(ClientInterface::class));
    }

    #[Test]
    public function psr17_factories_are_bound_so_the_cores_discovery_never_has_to_run(): void
    {
        $this->assertInstanceOf(RequestFactoryInterface::class, $this->container()->make(RequestFactoryInterface::class));
        $this->assertInstanceOf(StreamFactoryInterface::class, $this->container()->make(StreamFactoryInterface::class));
    }

    #[Test]
    public function registering_the_provider_binds_the_manager_and_merges_the_config(): void
    {
        // Registered here, against an application built in the test body, rather than relying
        // on the registration Testbench already did in setUp. Two reasons, and the second is
        // the important one:
        //
        // The bindings are asserted against an application that did not have them, so the
        // assertions depend on this call rather than on setUp's.
        //
        // And register() only runs under PHPUnit's error handler if it runs from here.
        // Laravel's HandleExceptions bootstrapper replaces that handler while the application
        // boots, which in a Testbench suite is during parent::setUp() - before
        // withoutDeprecationHandling() puts it back. So a deprecation raised by the provider's
        // own registration during setUp is discarded, and phpunit.xml's failOnDeprecation
        // never sees it.
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());

        (new LinodeServiceProvider($app))->register();

        $this->assertTrue($app->bound(LinodeManager::class));
        $this->assertTrue($app->bound(ClientInterface::class));
        $this->assertTrue($app->bound(RequestFactoryInterface::class));
        $this->assertTrue($app->bound(StreamFactoryInterface::class));
        $this->assertSame('main', $app->make(Config::class)->get('linode.default'));
    }

    #[Test]
    public function booting_the_provider_registers_the_config_to_publish(): void
    {
        // The other half of the test above, and not reached by it. Testbench boots the
        // application inside parent::setUp(), which is before withoutDeprecationHandling()
        // puts PHPUnit's error handler back - so boot() has always already run under
        // Laravel's swallowing handler, and a deprecation raised by configPath() or
        // publishes() on some future framework version would ship in silence. Probed: with
        // this test absent, a deprecation in boot() exits 0 and prints OK.
        //
        // ServiceProvider::$publishes is static and Testbench has already filled it, so
        // booting a second application overwrites the destination path that
        // the_config_file_is_publishable_under_its_own_tag asserts on - and which of the two
        // fails would depend on execution order. Hence the snapshot and the finally.
        $publishes = new ReflectionProperty(ServiceProvider::class, 'publishes');
        $groups = new ReflectionProperty(ServiceProvider::class, 'publishGroups');
        $savedPublishes = $publishes->getValue();
        $savedGroups = $groups->getValue();

        try {
            $app = new Application(__DIR__ . '/..');
            $app->instance('config', new ConfigRepository());

            $provider = new LinodeServiceProvider($app);
            $provider->register();
            $provider->boot();

            // By source path rather than destination: the destination is the throwaway
            // application's config directory, which says nothing about the package.
            $this->assertSame(
                [realpath(__DIR__ . '/../config/linode.php')],
                array_map(
                    'realpath',
                    array_keys(ServiceProvider::pathsToPublish(LinodeServiceProvider::class, 'linode-config'))
                ),
            );
        } finally {
            $publishes->setValue(null, $savedPublishes);
            $groups->setValue(null, $savedGroups);
        }
    }
}
