# hampel/linode-api-laravel

Laravel integration for `hampel/linode-api`. A service provider, a manager for named accounts,
and a facade — and one adapter that is the reason the package exists.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/LinodeManager.php` | one client per configured account, memoised |
| `src/LinodeServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Facades/Linode.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/linode.php` | the published config |

## What the package is for

The core package holds its own PSR-18 client, so nothing it sends is visible to `Http::fake()`.
`PendingRequestClient` replaces that client with one that sends through Laravel's handler stack,
so the fakes, `Http::assertSent()` and `Http::preventStrayRequests()` all reach it — while the
core package's request building, filtering, status mapping and exception hierarchy stay
untouched.

`tests/HttpFakeTest.php` is that claim, asserted. If it does not pass, the package has no reason
to exist.

## The adapter, and why it looks the way it does

Six decisions in the adapter and its binding are load-bearing and each has a way of looking like
clutter to be tidied away:

- **The pending request is rebuilt on every send.** `Factory::fake()` *replaces* the factory's
  stub collection, and `createPendingRequest()` copies whatever is there when it is called — so
  a client built once and kept holds a snapshot, and a fake registered after it was built never
  applies. `HttpFakeTest::faking_after_the_client_was_resolved_still_intercepts` is the test.
- **The adapter is bound under `linode.http_client`, never under the PSR-18 interface, and the
  manager is built only from that key.** `Psr\Http\Client\ClientInterface` is one container key
  shared by everything, and every sibling Laravel API wrapper used to bind it: with two installed,
  the provider registered last supplied every package's adapter, so a package's own timeouts and
  fixes never ran — measured on 2026-09-14 in an application with three wrappers. **There is
  deliberately no fallback to a `ClientInterface` bound elsewhere**, because an older sibling or an
  unrelated library may be what bound it, and taking it would silently lose `Http::fake()`. The
  `<config key>.http_client` shape is shared with the sibling wrappers so an application meets one
  override style. `TransportTest` registers a foreign `ClientInterface` provider both before and
  after this one; both tests failed against the old binding. **A recorder stub is not the
  realistic sibling**: another wrapper's adapter is a `PendingRequestClient` on the same faked
  factory, so the fake answers whichever adapter sends and only the timeout differs.
  `a_sibling_adapter_that_is_also_faked_does_not_lend_its_timeout` covers that shape, and against
  the old binding it failed with the sibling's 3.0 in place of `linode.timeout`.

  **The key is bound with `singletonIf()`, so an application's own binding survives provider
  order.** A full Laravel application registers discovered packages before its own providers, so
  an `AppServiceProvider` override wins either way; Laravel Zero registers `config/app.php` in list
  order, and the README lists `AppServiceProvider` first, where `singleton()` replaced the
  override silently. `HttpClientOverrideTest` lists an override provider first; under `singleton()`
  the package's own adapter sent instead — caught by `preventStrayRequests()` and a `.invalid`
  host rather than reaching Linode.
- **The factory itself is resolved on every send, through a closure the provider passes.**
  `Http::swap(new Factory)` binds a new factory into the container, and a client holding the one
  it was built with sent past the new fakes and past its `preventStrayRequests()` — to the real
  API, with the token. The constructor still accepts a `Factory` instance for hand-built clients;
  that form does not follow a swap. **The swap tests point `base_uri` at `api.linode.invalid`**,
  so a regression fails to resolve rather than reaching Linode — measured that way on 2026-09-14
  before the fix, and a closed local port is no substitute here, since under WSL mirrored
  networking it times out rather than refusing.
- **Options are passed to `send()` by hand, as a typed allowlist of transport options.**
  `PendingRequest` merges its options only inside its own `sendRequest()`, so sending on
  `buildClient()` directly dropped the configured timeouts and every `Http::globalOptions()`
  entry — measured as `null` on 2026-09-14. **Never pass them wholesale**: Guzzle applies
  `headers`, `query` and `json` on top of the request it is handed, so a global one would replace
  the core package's `Authorization`, query string or body. `TransportTest` pins both directions,
  and was probed both ways — passing nothing fails the timeout and proxy tests, passing
  everything fails the header/query/body test. Build the allowlist one key at a time: PHPStan
  2.1.22, which the `--prefer-lowest` corner resolves, loses the array shape of one built in a
  loop.
- **One Guzzle handler is shared across those rebuilds.** The handler owns curl's connection
  pool, so keep-alive survives even though the stack around it is new each time. Linode pages
  at 100, so an account with a few hundred zones — or one zone with a few hundred records —
  would otherwise pay a fresh TLS handshake per page.
- **`send()` rather than `sendRequest()`, with four options set by hand.** `laravel_data` and
  `on_stats` are `PendingRequest::sendRequest()`'s contract with the handler stack it builds;
  anything driving that stack without going through that method has to supply them. Laravel 13
  reads both defensively, **Laravel 12 does not.** The other three reproduce Guzzle's own
  `sendRequest()`, and `http_errors` must stay off.

  **All three failure modes were probed on Laravel 12 on 2026-09-13**, against 12.61.1 with
  `--prefer-lowest`, by removing each and running the suite:

  | removed | what happened |
  |---|---|
  | `laravel_data` | `ErrorException: Undefined array key "laravel_data"` on every request |
  | `on_stats` | `ErrorException: Undefined array key "on_stats"` on every request |
  | `http_errors => false` | a faked 404 arrived as `RequestException`, message `Could not reach the Linode API` |

  The third is the one that would survive review, because it fails as a plausible-sounding
  transport error rather than as a PHP notice. **Only the Laravel 12 CI job catches any of
  them** — on 13 the suite passes with the first two gone.

## The named-account accessor is `client()` because `account()` was taken

`Client::account()` is Linode's billing endpoint, `GET /v4/account`. The manager forwards
unknown calls to the default client, so an `account()` on the manager would shadow it and
`Linode::account()` would mean one thing on a single-account application and another on a
multi-account one. `FacadeConformanceTest::the_manager_does_not_shadow_a_client_method` keeps
that from being reintroduced for any name.

The config key is still `accounts`, because that is what they are.

## `version`, `page_size` and `base_uri` are top-level, not per-account

There is exactly one Linode, so those three describe the API rather than an account — and one
`Hampel\Linode\Api\Config` instance is shared by every account's client, which
`ManagerTest::one_config_is_shared_by_every_account` asserts because the constructor invites the
opposite assumption.

An application wanting a different version does not need a second config entry:
`withVersion()` returns a second client. That is the core package's design, because the version
is a URL segment and therefore moves every request.

The core package's `Config` validates all three, and the manager re-raises its
`InvalidArgumentException` as `InvalidConfiguration` — same failure, but a message naming
`linode.version` rather than an argument, with the original kept as `getPrevious()`.

## Facts worth not rediscovering

- **Only a 204 is a success with no body, so an empty 200 raises.** `Http::fake()` with no
  arguments answers every request with an empty 200, so a forgotten fixture fails loudly
  instead of reporting an empty account. It was the other way round until `hampel/linode-api`
  0.2.0, and this package is why it changed: the wrapper reported that the wider check made
  the one failure it could not make loud, and the core settled it by measuring a real
  successful DELETE — `Content-Type: application/json`, `Content-Length: 2`, body `{}`, which
  decodes like anything else.

  **Both arms are pinned, deliberately.**
  `ExceptionPassthroughTest::faking_with_no_arguments_fails_loudly_rather_than_reporting_an_empty_account`
  covers the raise; `a_genuine_204_is_still_an_empty_response_rather_than_a_failure` covers
  the success, through `GET profile/grants` on an unrestricted user. A branch whose arms are a
  raise and a success is the shape where a single-arm test reads as coverage and is not — the
  core's suite was green on the original defect and would have stayed green if the branch had
  been narrowed the wrong way.
- **The token variable is `LINODE_API_TOKEN`, and `LINODE_TOKEN` is read with `?:`, not as
  `env()`'s default.** The rename brings it in line with the sibling wrappers' `*_API_TOKEN`
  without breaking an existing `.env`. The obvious form, `env('LINODE_API_TOKEN',
  env('LINODE_TOKEN'))`, fails in the one situation a rename creates: a blank
  `LINODE_API_TOKEN=` line copied from an updated example while the real token still sits under
  the old name is an empty string, not null, so the default never applies and the account is
  refused for having no token. `ConfigurationTest` pins all five cases, and with the `env()`
  default form it fails exactly the blank one.
- **The 401-versus-401 discrimination rides entirely on response headers.** An insufficient
  scope answers 401, not 403, and `X-OAuth-Scopes` is the only thing separating it from a bad
  credential. A transport that dropped or rewrote headers would turn *widen the token's scopes*
  into *replace the credential* with nothing to say it had, which is why
  `ExceptionPassthroughTest` asserts both halves through a fake rather than trusting the core
  package's own suite.
- **`X-Filter` is a request header, so a filtered lookup leaves no evidence in the URL.** A
  consumer asserting that it looked a zone up by name has to match on the header.
  `HttpFakeTest::the_filter_header_is_visible_to_an_assertion` is the demonstration.
- **`ResponseReceived` and `ConnectionFailed` do not fire; `RequestSending` does.** The first two
  are dispatched from `PendingRequest::send()`, a layer above the handler stack, which the
  adapter never calls. `RequestSending` comes from a before-sending callback that
  `PendingRequest`'s constructor registers, and `buildBeforeSendingHandler()` runs it *inside*
  the stack that `buildClient()->send()` drives. Telescope's HTTP client watcher listens for
  `ResponseReceived` and `ConnectionFailed` only, so it still shows nothing; the core package's
  PSR-3 logging is what does.

  This package's docs said all of it did not fire until 2026-09-13 — a correct conclusion
  resting on a half-false mechanism, found when an `Event::assertNothingDispatched()` in a
  consuming test counted a `RequestSending`. `TransportTest` now measures
  all three, **each against Laravel's own `Http::get()` as a control**, because an
  `assertSame(0, …)` on an event count passes just as well when the listener was never wired.
- **`failOnDeprecation` is inert in a Testbench package without help.** Laravel's
  `HandleExceptions` replaces PHPUnit's error handler when the application boots.
  `withoutDeprecationHandling()` in `setUp()` fixes it for test-executed paths — but the
  provider's own `register()` has already run inside `parent::setUp()`, so
  `ConfigurationTest::registering_the_provider_binds_the_manager_and_merges_the_config`
  registers it again against an application built in the test body.
- **Laravel Zero does not bind the HTTP client factory, and Testbench cannot see that.** Laravel
  binds `Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`; Laravel
  Zero's provider set is Build, Cache, Collision, CommandRecorder, Composer, Filesystem,
  GitVersion and NullLogger, and `app:install http` only runs `composer require
  illuminate/http`. Unbound, every `make()` builds a fresh factory, so the package holds a
  different one from the facade and `Http::fake()` does not intercept — the request reaches the
  real API. `singletonIf` in the provider closes it.

  `Http::fake()` hides the ordering, which is what makes it dangerous: `fake()` calls
  `Facade::swap()`, which binds its instance into the container, so faking *before* the client
  is resolved happens to work and faking after does not. `tests/LaravelZeroTest.php` builds the
  container by hand because Testbench always boots a full application and can never reach this.

  **Laravel Zero also ignores package discovery**, which that test cannot see: its `Application`
  sets the package manifest to `[]`, so a consumer lists the provider in `config/app.php` and
  the global `Linode` alias never exists. The facade imported by class name works, and
  `Http::fake()` intercepts through it. `vendor:publish --tag=linode-config` works too, once the
  provider is listed — `publishes()` runs in the provider's `boot()` — but Laravel Zero lists it
  under `hidden` in `config/commands.php`, so it is absent from `list`.

  The README said the reverse of the discovery part until 2026-09-13, when a consuming Laravel
  Zero tool met a null `config('linode')` on its first call. **The correction then claimed
  there was no `vendor:publish`, and was wrong the same day**: it was checked with `list`, which
  cannot see a hidden command, and a publish attempted without the provider answers `No
  publishable resources` — which reads as confirmation. Run the command, with the provider
  listed, before concluding a Laravel Zero command is missing.

- **`composer-require-checker` carries the undeclared-dependency check here, not the dev-free
  PHPStan job.** `laravel/framework` `replace`s every `illuminate/*` component, so the framework
  supplies every `Illuminate` symbol whether its component was declared or not. The five
  whitelisted symbols are that same `replace` — there is no `vendor/illuminate/` for the checker
  to attribute them to, verified on the `--no-dev` tree. A *new* `Illuminate` symbol appearing
  there is a prompt to check `require`, not to extend the list.

  **It is not in `composer check`, so it does not run with the rest.** The tool lives outside
  the package by design, which means nothing local re-runs it when an import changes and only
  CI notices. **Run it by hand after adding or changing any `use` in `src/`** — CI gets the
  binary from `setup-php`'s `tools:` input, which has no equivalent on a workstation:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

  Note it does **not** report `Illuminate\Contracts\Container\Container`, which the provider
  imports and uses as a closure parameter type. It is declared (`illuminate/contracts` is in
  `require`) so nothing is wrong, but do not read the whitelist as a complete inventory of the
  Illuminate symbols in `src/`.

## The facade's annotations are the only types it has

`Linode::domains()` goes through the manager's `__call()` and returns `mixed`; the
`@method static` block is what makes `Linode::domains()->all()` analysable. So an accessor added
to the core package's `Client` in a later release is a call that works at runtime and silently
loses its type. The core package reaching 1.0.0 does not retire that risk, because an accessor
is an addition rather than a break and a minor release is free to make one — which is the
release a consumer upgrades into without reading anything.
`tests/FacadeConformanceTest.php` compares the two lists in both directions and checks every
annotated return type resolves.

The manager needs the same coverage and gets it from one `@mixin Client` line, which cannot
drift. The facade cannot use `@mixin` because `__callStatic()` needs `@method static`.

## Nothing here re-exposes an entity method, and `effectiveTtl()` is why

The rule outlived the case that produced it, which is the reason to keep it written down rather
than delete it.

`DomainRecord::effectiveTtl()` was the open question that kept the core package at 0.x: whether
a record's `ttl_sec` of 0 inherits the fixed 86400 or the zone's own TTL was undocumented, and
the two answers give the method different signatures. It has since been settled by measurement —
it inherits the zone's, proven by moving a live zone's TTL and watching the zero-TTL records
follow — and the method gained an optional `$zone` parameter in the core's 0.3.0 before freezing
at 1.0.0.

**So the specific risk is closed and the practice stands.** An entity method reached through a
facade `@method` line or a convenience wrapper here is a signature this package would be
pinning and does not own; a consumer calls it on the entity the core package handed them. The
facade annotates the client's accessors and stops there.

## No harness

Everything worth exercising here is container and configuration wiring, which Testbench sees.
The core package's harness drives the real API calls.

It becomes worth reconsidering if this package grows something it owns end to end — an artisan
command, a queue-worker interaction, a retry policy over the rate-limit headers. Until then a
harness would need a container and a config repository, and Laravel would end up inside it,
which is the opposite of what a harness is for.
