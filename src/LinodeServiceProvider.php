<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\Linode\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Linode\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the Linode API manager into the container.
 *
 * The transport is bound under linode.http_client - never under Psr\Http\Client\ClientInterface -
 * and the manager is built only from that key. Rebind or decorate linode.http_client and every
 * account's client picks the replacement up. The default sends through Laravel's HTTP client,
 * which is what makes the package's traffic visible to Http::fake() - see PendingRequestClient.
 *
 * NOT THE PSR-18 INTERFACE, DELIBERATELY. That is one container key shared by everything that
 * speaks PSR-18, and the sibling Laravel API wrappers used to claim it too: with two installed,
 * the provider registered last supplied every package's adapter, with its own timeouts, and a
 * package's own fixes never ran. A package-specific key cannot collide, and there is no
 * fallback to a ClientInterface bound elsewhere - an older sibling or an unrelated library could
 * be the one that bound it, and the manager would silently take it, losing Http::fake().
 */
final class LinodeServiceProvider extends ServiceProvider
{
    /**
     * The container key the transport is bound under, and the documented override point. The
     * same `<config key>.http_client` shape as the sibling wrappers, so an application meets one
     * override style.
     */
    public const HTTP_CLIENT = 'linode.http_client';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/linode.php', 'linode');

        // Laravel binds the HTTP client factory as a singleton in FoundationServiceProvider,
        // which a full application registers and a Laravel Zero one does NOT - its provider
        // set is Build, Cache, Collision, CommandRecorder, Composer, Filesystem, GitVersion
        // and NullLogger, and nothing there binds it. `app:install http` does not either: it
        // runs `composer require illuminate/http` and stops. Unbound, the container builds a
        // fresh Factory on every make(), so the one this package holds is not the one the Http
        // facade configures, and Http::fake() silently fails to intercept: the request goes to
        // the real API.
        //
        // Http::fake() hides the ordering, which is what makes it dangerous. fake() calls
        // Facade::swap(), which binds its instance into the container - so faking BEFORE the
        // client is resolved happens to work, and faking after it does not. Binding a
        // singleton here removes the ordering question on both platforms.
        //
        // singletonIf, so a full Laravel application keeps the framework's own binding and
        // this is a no-op there.
        $this->app->singletonIf(HttpClientFactory::class, static fn (Container $app): HttpClientFactory => new HttpClientFactory(
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's fills both roles and laravel/framework requires it, so the fallback
        // resolves in every application this package can run in. The core package would find
        // one itself through Psr17Discovery; binding them means an application can say which,
        // and means the discovery never runs in the one place a wrong answer would be silent.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        // singletonIf, so an application's own binding of the key survives whichever order the
        // providers register in. A full Laravel application registers discovered packages before
        // its own providers, but Laravel Zero registers config/app.php in list order, and an
        // AppServiceProvider listed first would otherwise have its override replaced here,
        // silently. No sibling package binds this key, so keeping an existing binding cannot
        // bring back the shared-PSR-18 collision.
        $this->app->singletonIf(self::HTTP_CLIENT, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // The Factory the Http facade resolves, looked up again on every send, which is
            // what puts this package's requests among the ones Http::fake() and
            // Http::assertSent() see - including after Http::swap() has replaced it.
            $app = $this->app;

            return new PendingRequestClient(
                static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                $this->seconds($config->get('linode.timeout'), 10.0),
                $this->seconds($config->get('linode.connect_timeout'), 5.0),
            );
        });

        $this->app->singleton(LinodeManager::class, function (): LinodeManager {
            $client = $this->app->make(self::HTTP_CLIENT);

            // An application can bind the key to anything, so check rather than let a wrong
            // binding surface as a TypeError one layer further in.
            if (! $client instanceof ClientInterface) {
                throw InvalidConfiguration::httpClient(self::HTTP_CLIENT, $client);
            }

            return new LinodeManager(
                $this->app->make(Config::class),
                $client,
                $this->app->make(RequestFactoryInterface::class),
                $this->app->make(StreamFactoryInterface::class),
                $this->app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // configPath() rather than the config_path() helper: the helper is defined by
            // illuminate/foundation, which this package does not require and should not.
            // Requiring only the components it uses is a claim that the package needs no full
            // application, and Testbench -- which boots one -- would never catch the helper
            // contradicting it.
            $this->publishes([
                __DIR__ . '/../config/linode.php' => $this->app->configPath('linode.php'),
            ], 'linode-config');
        }
    }

    private function seconds(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
