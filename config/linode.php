<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Account
    |--------------------------------------------------------------------------
    |
    | Which entry under "accounts" a call without a name uses: Linode::domains() is
    | Linode::client(<this>)->domains(). An application managing one account never
    | needs to name it.
    |
    */

    'default' => env('LINODE_ACCOUNT', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | One entry per Linode account, named however you like -- the name is what
    | Linode::client('...') takes. Each needs a token and nothing else: there is one
    | Linode, so unlike a self-hosted API there is no URL to configure per account.
    |
    | A personal access token and an OAuth access token are the same thing on the
    | wire -- same header, same scopes, same X-OAuth-Scopes in the reply -- so either
    | goes here. What the token may do is decided by its scopes, which
    | Linode::verify() reports.
    |
    | An account with no token is refused when its client is built rather than
    | allowed to 401 on first use. Every Linode endpoint needs a credential; there is
    | no anonymous request to fall back to.
    |
    */

    'accounts' => [

        'main' => [
            'token' => env('LINODE_TOKEN'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | "v4" or "v4beta", and nothing else -- anything else is refused rather than
    | 404ing on every call.
    |
    | This is a URL SEGMENT rather than a header, so it moves every request a client
    | makes, including the ones that were never in beta. Set here it is the default
    | for every account; for one call, or one job, ask a client for a second one
    | pointed elsewhere:
    |
    |     Linode::withVersion('v4beta')->connection()->get('some/beta/endpoint');
    |
    */

    'version' => env('LINODE_API_VERSION', 'v4'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | How many items a list request asks for when the caller does not say. Null uses
    | the API's own default of 100.
    |
    | LINODE REFUSES ANYTHING BELOW 25 with a 400, and anything above 500. Both are
    | checked here rather than costing a round trip to discover.
    |
    */

    'page_size' => env('LINODE_PAGE_SIZE'),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The API root WITHOUT the version segment. Null uses Linode's own host, which is
    | what all but two situations want: a recorded fixture served locally, and an
    | outbound proxy that terminates the connection.
    |
    */

    'base_uri' => env('LINODE_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, so a consumer's own
    | Http::globalOptions() and Http::globalRequestMiddleware() apply alongside them.
    |
    | There is no redirect setting: Guzzle's PSR-18 entry point does not follow
    | redirects, and no Linode endpoint answers one.
    |
    */

    'timeout' => env('LINODE_TIMEOUT', 10),

    'connect_timeout' => env('LINODE_CONNECT_TIMEOUT', 5),

];
