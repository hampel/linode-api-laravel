<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel;

use BadMethodCallException;
use Hampel\Linode\Api\Authentication\AccessToken;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config as ApiConfig;
use Hampel\Linode\Api\Exception\InvalidArgumentException;
use Hampel\Linode\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Linode\Api\Laravel\Exception\UnknownAccount;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One Linode client per configured account.
 *
 *     Linode::domains()->all();                          // the default account
 *     Linode::client('reseller')->domains()->all();      // a named one
 *
 * IT IS NAMED client() AND NOT account(), which looks like a worse word and is the only one
 * available: `Client::account()` is Linode's billing endpoint, and the manager forwards
 * unknown calls to the default client, so an account() here would shadow it. A facade where
 * `Linode::account()` sometimes means a client and sometimes means `GET /v4/account` would be
 * worse than an imperfect name.
 *
 * Clients are memoised per name. The transport underneath them is not - see
 * PendingRequestClient, which resolves Laravel's HTTP factory at the moment of sending so
 * that `Http::fake()` works whenever it is called.
 *
 * The @mixin is what makes $manager->domains() analysable: __call() forwards anything the
 * client answers to, and one line that cannot drift says so. The facade repeats the list
 * explicitly because @method static is the only form __callStatic() can carry, and a test
 * keeps that copy honest.
 *
 * @mixin Client
 */
final class LinodeManager
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * Which API, and how its URLs are built.
     *
     * ONE INSTANCE, SHARED BY EVERY ACCOUNT'S CLIENT, because there is exactly one Linode -
     * the version, page size and base URI describe the API rather than an account, so they
     * are top-level settings rather than per-account ones. An application wanting one client
     * elsewhere asks a client for it: `withVersion()` returns a second client rather than
     * mutating this.
     */
    private ?ApiConfig $apiConfig = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The client for a configured account, or for the default when no name is given.
     */
    public function client(?string $name = null): Client
    {
        $name ??= $this->getDefaultAccount();

        return $this->clients[$name] ??= $this->build($name);
    }

    public function getDefaultAccount(): string
    {
        $default = $this->config->get('linode.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured account names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredAccounts(): array
    {
        $accounts = $this->config->get('linode.accounts');

        return is_array($accounts) ? array_values(array_filter(array_keys($accounts), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $settings = $this->config->get('linode.accounts.' . $name);

        if (! is_array($settings)) {
            throw UnknownAccount::named($name, $this->configuredAccounts());
        }

        $token = $this->string($settings, 'token');

        if ($token === null) {
            throw InvalidConfiguration::missingToken($name);
        }

        return new Client(
            $this->api(),
            new AccessToken($token),
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
        );
    }

    /**
     * The core package's Config, built from the top-level settings and memoised.
     *
     * Its constructor validates: a version that is not `v4` or `v4beta`, a page size outside
     * Linode's 25-500, and a base URI with no scheme are all refused here rather than on the
     * first request. The refusal is re-raised as InvalidConfiguration so the message names
     * the configuration and not an argument.
     */
    private function api(): ApiConfig
    {
        if ($this->apiConfig !== null) {
            return $this->apiConfig;
        }

        $version = $this->config->get('linode.version');
        $baseUri = $this->config->get('linode.base_uri');
        $pageSize = $this->config->get('linode.page_size');

        try {
            return $this->apiConfig = new ApiConfig(
                is_string($version) && trim($version) !== '' ? trim($version) : ApiConfig::VERSION_STABLE,
                is_string($baseUri) && trim($baseUri) !== '' ? trim($baseUri) : null,
                is_numeric($pageSize) ? (int) $pageSize : null,
            );
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::api($e);
        }
    }

    /**
     * One setting, as a non-empty string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches
     * config as an empty string as readily as it reaches it as null, and "" is never a
     * meaningful token. Without this the client would present `Authorization: Bearer ` and
     * Linode would answer "Invalid Token", which describes a credential that was never sent.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Anything else goes to the default account's client, so a single-account application
     * never has to name one: `Linode::domains()` rather than `Linode::client('main')->domains()`.
     *
     * The facade carries a @method line for each of these, which is where the types come
     * from - see Facades\Linode, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
