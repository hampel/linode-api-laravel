<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Facades;

use Hampel\Linode\Api\Authentication\Authentication;
use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Config;
use Hampel\Linode\Api\Connection;
use Hampel\Linode\Api\Endpoint\Account;
use Hampel\Linode\Api\Endpoint\DomainRecords;
use Hampel\Linode\Api\Endpoint\Domains;
use Hampel\Linode\Api\Endpoint\Endpoint;
use Hampel\Linode\Api\Endpoint\Profile;
use Hampel\Linode\Api\Result\TokenStatus;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Linode API manager.
 *
 *     Linode::domains()->all();                          // the default account
 *     Linode::client('reseller')->domains()->all();      // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the
 * endpoint classes carry the typed signatures from there. Without them every call through the
 * facade is untyped to both the IDE and PHPStan, which is most of what a facade costs you.
 *
 * Everything after client() mirrors a method on the core package's Client, reached through the
 * manager's __call(). FacadeConformanceTest asserts that the two lists stay identical, so a
 * method added to the client in a later release shows up as a failing test rather than as a
 * call that silently loses its type.
 *
 * NOTE WHICH account() THIS IS. It is Linode's billing endpoint - `GET /v4/account` - and not
 * a way to reach a configured account, which is client(). The core package's own naming
 * decides this one; see LinodeManager.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic,
 * returning the class it was handed, and a @method line cannot express that. Reach for
 * `Linode::client()->endpoint(Foo::class)` where the generic return matters.
 *
 * @method static Client client(?string $name = null)
 * @method static string getDefaultAccount()
 * @method static list<string> configuredAccounts()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static TokenStatus verify()
 * @method static Client withCredential(Authentication $authentication)
 * @method static Client withVersion(string $version)
 * @method static Endpoint endpoint(string $class)
 * @method static Profile profile()
 * @method static Account account()
 * @method static Domains domains()
 * @method static DomainRecords records()
 * @method static Connection connection()
 *
 * @see \Hampel\Linode\Api\Laravel\LinodeManager
 * @see \Hampel\Linode\Api\Client
 */
final class Linode extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\Linode\Api\Laravel\LinodeManager::class;
    }
}
