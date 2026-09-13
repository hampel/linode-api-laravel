# Linode API for Laravel

[![Tests](https://github.com/hampel/linode-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/linode-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/linode-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/linode-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/linode-api-laravel.svg?style=flat-square)](https://github.com/hampel/linode-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/linode-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/linode-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/linode-api`][core] — a service provider, a manager for named
accounts, and a facade.

Two things it adds that an application would otherwise write for itself:

- **`Http::fake()` sees the API client's traffic.** The core package carries its own PSR-18
  client, so by default Laravel's HTTP fakes know nothing about it and an application has to
  fake at the transport library instead. Here every request goes through Laravel's own handler
  stack, so `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` all work.
- **Named accounts.** A token per account, a default, and `Linode::client('name')` to reach
  one — the shape Laravel's own database and mail managers take.

It supplies the transport and nothing else. The core package's request building, filtering,
status mapping and exception hierarchy are untouched, which is the point: a 401 and a 404 stay
different exceptions rather than both becoming an unsuccessful response.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero works too, and needs the HTTP component, which an application opts into with
`php <app> app:install http` (that command runs `composer require illuminate/http` and nothing
else). It is the likelier home for this package in any case: a tool that reconciles DNS from a
file, or renews a certificate, is a command rather than a web request.

**Laravel Zero ignores package discovery, so the provider has to be listed by hand** — see
[Laravel Zero](#laravel-zero) under Installation.

One other difference is handled for you and worth knowing about. Laravel binds
`Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`, which a Laravel
Zero application does not register — and the HTTP component installs the classes without
binding anything. Unbound, the container builds a fresh factory on every resolution, so the one
this package holds is not the one `Http::fake()` configures, and the fake silently fails to
intercept: the request goes to the real API. This package binds a singleton when nothing else
has, so the behaviour is the same on both platforms.

## Installation

```bash
composer require hampel/linode-api-laravel
```

In a Laravel application the provider and the `Linode` alias are discovered automatically.
Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=linode-config
```

### Laravel Zero

**Laravel Zero skips package discovery entirely**, so nothing this package declares for discovery
reaches it: the provider is not loaded, `config('linode')` is null, and the manager cannot be
built. List the provider in `config/app.php`:

```php
'providers' => [
    App\Providers\AppServiceProvider::class,
    Hampel\Linode\Api\Laravel\LinodeServiceProvider::class,
],
```

**The facade still works; the global `Linode` alias does not.** Import it by class name, which the
examples below already do:

```php
use Hampel\Linode\Api\Laravel\Facades\Linode;
```

**`vendor:publish` works, but only once the provider is listed**, and Laravel Zero hides it from
`list`. Before that it answers `No publishable resources for tag [linode-config]` and writes
nothing, which reads as unsupported. With the provider in `config/app.php`:

```bash
php <app> vendor:publish --tag=linode-config
```

## Configuration

An account needs a token. Nothing else is required — there is exactly one Linode, so unlike a
self-hosted API there is no URL to configure.

```dotenv
LINODE_API_TOKEN=your-personal-access-token
```

`LINODE_TOKEN`, the name earlier releases documented, is still read when `LINODE_API_TOKEN` is unset
or empty, so an existing `.env` keeps working.

**A personal access token and an OAuth access token are the same thing here.** Linode does not
distinguish them on the wire: same header, same scopes, same `X-OAuth-Scopes` in the reply. So
there is one setting and not two, and what the token may *do* is decided by its scopes —
`Linode::verify()` reports them.

The shipped `config/linode.php` defines one account called `main`. Add more by naming them:

```php
'default' => 'production',

'accounts' => [
    'production' => [
        'token' => env('LINODE_API_TOKEN'),
    ],

    'staging' => [
        'token' => env('STAGING_LINODE_API_TOKEN'),
    ],
],
```

**An account with no token is refused when its client is built**, rather than allowed to reach
the API and come back 401. Every Linode endpoint needs a credential, so there is no anonymous
request to fall back to — and a 401 from an unset environment variable reads as a revoked token,
which sends whoever is debugging it to the wrong place. It raises
`Hampel\Linode\Api\Laravel\Exception\InvalidConfiguration`, which extends the core package's
`LinodeException`, so an application already catching that catches misconfiguration too. An
empty string counts as no token: an unset environment variable reaches config as `""` as readily
as it reaches it as `null`.

### Which API, and how much of it per request

These three describe the API rather than an account, so they are top-level settings shared by
every account's client:

```php
'version' => env('LINODE_API_VERSION', 'v4'),   // v4 or v4beta, and nothing else
'page_size' => env('LINODE_PAGE_SIZE'),         // null uses the API's own default of 100
'base_uri' => env('LINODE_API_URL'),            // null uses Linode's own host
```

**`version` is a URL segment rather than a header, so it moves every request a client makes** —
including the ones that were never in beta. Set here it is the default; for one job, ask a
client for a second one pointed elsewhere:

```php
Linode::withVersion('v4beta')->connection()->get('some/beta/endpoint');
```

**Linode refuses a `page_size` below 25 with a 400**, and anything above 500. Both are checked
when the client is built rather than costing a round trip to discover. `base_uri` exists for
two situations and no others: a recorded fixture served locally, and an outbound proxy that
terminates the connection.

### Transport

```php
'timeout' => 10,
'connect_timeout' => 5,
```

Applied to every request. `Http::globalRequestMiddleware()` applies too.

**Of `Http::globalOptions()`, only transport options apply** — timeouts, TLS (`verify`, `cert`,
`ssl_key` and their types, `crypto_method`), `proxy`, `version`, `force_ip_resolve`,
`decode_content` and `curl`. Options that would change the request itself — `headers`, `query`,
`json`, `form_params`, `body` and the like — are deliberately not passed on, because they would
replace the `Authorization` header, query string or body the core package built.

There is no redirect setting. Guzzle's PSR-18 entry point does not follow redirects, and no
Linode endpoint answers one.

## Usage

The facade reaches the default account directly:

```php
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Entity\DomainRecord;

Linode::verify();                                          // does this token work?

$zone = Linode::domains()->findByName('example.com');      // null when there is no such zone

Linode::domains()->records($zone)->create(
    DomainRecord::a('www', '203.0.113.10')->withTtl(300)
);
```

Name an account to reach another:

```php
$zones = Linode::client('staging')->domains()->all();
```

**It is `client()` and not `account()`, which is the one wart in the API and is not an
oversight.** `Linode::account()` is the core package's billing endpoint — `GET /v4/account` —
and the manager forwards unknown calls to the default client, so a method on both would have a
meaning that depended on which class you thought you were calling.

Everything past that point is the core package — see [its documentation][core] for the
endpoints, the entities, filtering, pagination, TTL rounding and how to reach one of the
roughly three hundred endpoints neither package wraps.

Inject the manager where a facade is not wanted:

```php
use Hampel\Linode\Api\Laravel\LinodeManager;

public function __construct(private readonly LinodeManager $linode) {}

$this->linode->client('staging')->domains()->all();
```

### A token that is not in the configuration

An application resolving a credential per tenant, or holding one a user has just pasted in,
does not want a config entry for it. `withCredential()` answers with a new client over the same
transport:

```php
use Hampel\Linode\Api\Authentication\AccessToken;

$linode = Linode::withCredential(new AccessToken($tenant->linode_token));
```

A new client rather than a mutation, so two credentials in one long-running process — a queued
job walking several tenants — cannot leak into each other's requests.

### Returning an entity

Entities implement `\JsonSerializable` and serialise to Linode's own payload, so handing one to
a JSON response gives you what the API answered rather than the entity's internals:

```php
return response()->json(Linode::domains()->get($id));
```

A field Linode added after this release survives that, because the payload it was built from is
kept.

### Errors

The core package's exceptions arrive untouched. Telling them apart is the reason to use it
rather than `Http::` directly — a client that reports every unsuccessful status the same way
cannot tell a zone that is not there from a token that may not see it:

```php
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\NotAuthenticatedException;
use Hampel\Linode\Api\Exception\NotPermittedException;

try {
    $zone = Linode::domains()->get($id);
} catch (NotFoundException $e) {
    // no such zone on this account
} catch (NotPermittedException $e) {
    // the token is real and lacks the scope, or the user lacks the grant
} catch (NotAuthenticatedException $e) {
    // the credential is no good. A configuration error, not an empty result.
}
```

**An insufficient OAuth scope answers 401 on this API, not 403.** The core package raises
`NotPermittedException` for it anyway, discriminated on the `X-OAuth-Scopes` header — Linode can
only report a token's own scopes for a token it recognises, so a 401 that names them is a scope
failure and a 401 that says `unknown` is a bad credential. That matters here because the whole
discrimination rides on response *headers*, which this package is responsible for handing back
intact. If you map exceptions to HTTP responses anywhere, note that
`NotPermittedException::$statusCode` can be 401.

## Testing

Fake the API with the vocabulary the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'api.linode.com/*' => Http::response([
        'data' => [['id' => 1234, 'domain' => 'example.com', 'type' => 'master']],
        'page' => 1, 'pages' => 1, 'results' => 1,
    ]),
]);

$zone = Linode::domains()->findByName('example.com');

Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
```

The package's real code path runs; only the socket is replaced. So a faked 404 still arrives as
`NotFoundException`, and a faked 200 whose body is HTML still arrives as
`MalformedResponseException`.

Four things worth knowing:

- **Give every fake a body.** `Http::fake()` with no arguments answers every request with an
  empty 200, and the client raises `MalformedResponseException` on one — only a 204 is a
  success with no body on this API. That is worth knowing rather than discovering: before
  `hampel/linode-api` 0.2.0 an empty 200 resolved to an empty response, so a forgotten fixture
  reported *this account has no zones* instead of failing.
- **`X-Filter` is where the evidence is.** Filtering on this API is a request header, not a
  query string, so an assertion that you looked a zone up by name has nothing in the URL to
  match on: `$request->hasHeader('X-Filter', '{"domain":"example.com"}')`.
- **Request bodies are assertable** — `$request['ttl_sec']` works, because the core package
  writes `application/json` and `Illuminate\Http\Client\Request::isJson()` is what gates the
  parsing.
- **Order does not matter.** Faking after the client has been resolved works, and so does
  `Http::swap()`, because the transport resolves Laravel's HTTP factory at the moment of sending
  rather than when it was built.

Replace the transport entirely by binding `linode.http_client`, which is how an application with
its own outbound HTTP policy — a proxy-aware or SSRF-guarded client that everything is required
to go through — makes this package use it:

```php
$this->app->singleton('linode.http_client', fn ($app) => $app->make(MyPolicyClient::class));
```

**Binding `Psr\Http\Client\ClientInterface` does not reach this package.** That key is shared by
everything that speaks PSR-18, and the package deliberately neither binds it nor reads it, so
installing another API wrapper — or any library that binds it — cannot take over Linode's
transport. An application routing several API packages through one client binds each package's
`<config key>.http_client`.

### What is not visible

**`ResponseReceived` and `ConnectionFailed` do not fire for this traffic, so Telescope's HTTP
client watcher — which listens for exactly those two — will not show it.** Laravel raises both
from a layer above the handler stack, and this package sends through the stack directly.

**`RequestSending` does fire**, once per request, because Laravel raises it from inside the stack.
A listener that pairs `RequestSending` with `ResponseReceived` will therefore see requests that
never get a response, and `Event::assertNothingDispatched()` will count them.

The core package logs every request through PSR-3 instead, which reaches the application log:
requests at `debug`, failures at `error`, and a nearly-spent rate limit at `warning`. The token
is never logged.

## What this package will not do

Grow retries or backoff. `ResponseMeta` carries the rate limit on every response and
`TooManyRequestsException` is typed, so an application can slow itself down or retry; which
requests are safe to retry is the application's knowledge, not this package's.

## Versioning

`hampel/linode-api` is constrained at `^1.0`. From 1.0.0 a break in any of its classes,
methods or signatures means a new major, so an upgrade inside `^1.0` cannot move anything this
package hands you.

What that stability covers is the whole of the core package's surface: request building,
filtering, the entities, and the exception hierarchy. This package adds the transport, the
manager and the facade, and its own exceptions extend the core's — so an application catching
`Hampel\Linode\Api\Exception\ExceptionInterface` is already catching everything either package
raises.

## License

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/linode-api
