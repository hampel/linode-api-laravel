<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests\Fixture;

use Hampel\Linode\Api\Laravel\LinodeServiceProvider;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * An application's own provider overriding linode.http_client - standing in for an
 * AppServiceProvider listed before the package's provider, as Laravel Zero's config/app.php does.
 */
final class HttpClientOverrideProvider extends ServiceProvider
{
    public static ?RecordingPsr18Client $client = null;

    public function register(): void
    {
        $client = self::$client = new RecordingPsr18Client();

        $this->app->singleton(LinodeServiceProvider::HTTP_CLIENT, static fn (): ClientInterface => $client);
    }
}
