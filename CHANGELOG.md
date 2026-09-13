# CHANGELOG

## Unreleased

- A client resolved before `Http::swap()` now sends through the swapped-in factory, so its fakes
  and `preventStrayRequests()` apply
- The configured `timeout` and `connect_timeout`, and transport options from
  `Http::globalOptions()` such as `proxy` and `verify`, now reach each request. Global `headers`,
  `query` and body options are not applied
- `PendingRequestClient` accepts a closure resolving the HTTP factory, as well as a `Factory`

## 1.1.0 (2026-09-13)

- `LINODE_API_TOKEN` is the documented token variable. `LINODE_TOKEN` is still read when
  `LINODE_API_TOKEN` is unset or empty
- README: under Laravel Zero, list `LinodeServiceProvider` in `config/app.php` and import the
  facade by class name; Laravel Zero does not discover packages. `vendor:publish` works once the
  provider is listed
- Corrected the documentation of Laravel's HTTP client events: `RequestSending` fires for
  requests the API client makes; `ResponseReceived` and `ConnectionFailed` do not

## 1.0.0 (2026-09-13)

Initial release.

- `LinodeServiceProvider` binds `LinodeManager`, merges `config/linode.php` and publishes it
  under the `linode-config` tag
- `LinodeManager` builds one `Hampel\Linode\Api\Client` per configured account, memoised by
  name; a call naming no account is forwarded to the default
- `Linode` facade, with a `@method` annotation for each accessor on `Client`
- `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` apply to requests the
  API client makes. Request building, filtering, status mapping and the exception hierarchy are
  the core package's throughout, and its exceptions reach the caller unchanged
- `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not bound
  one, as a Laravel Zero application does not
- `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's
  requests through an application's own HTTP client
- `version`, `page_size` and `base_uri` are top-level settings, shared by every account's
  client; the core package validates all three when the client is built
- `UnknownAccount` and `InvalidConfiguration` extend the core package's `LinodeException`. An
  account with no token, and a version or page size the API would refuse, are reported when the
  client is built
- Requires `hampel/linode-api` `^1.0`, PHP 8.3, and Laravel 12 or 13
