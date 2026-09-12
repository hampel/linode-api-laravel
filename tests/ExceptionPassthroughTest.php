<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Exception\ClientException;
use Hampel\Linode\Api\Exception\MalformedResponseException;
use Hampel\Linode\Api\Exception\NotAuthenticatedException;
use Hampel\Linode\Api\Exception\NotFoundException;
use Hampel\Linode\Api\Exception\NotPermittedException;
use Hampel\Linode\Api\Exception\ServerException;
use Hampel\Linode\Api\Exception\TooManyRequestsException;
use Hampel\Linode\Api\Exception\ValidationException;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * This is the property to protect above ergonomics. What the core package is worth is not its
 * transport but its taxonomy: a client that reports every unsuccessful status the same way
 * cannot tell a zone that does not exist from a token that may not see it - both are an empty
 * result - and only one of them is a configuration error that should fail loudly.
 *
 * Laravel's own Http:: is the thing that flattens them, which is why an application reaching
 * for this package must not get that flattening back by the side door. Each case below would
 * be an indistinguishable "unsuccessful response" through the facade.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected value, with the field on it' => [400, ValidationException::class],
            'an unusable token is not an empty result' => [401, NotAuthenticatedException::class],
            'a restricted user without the grant' => [403, NotPermittedException::class],
            'a missing zone' => [404, NotFoundException::class],
            'rate limiting is typed so a caller can retry' => [429, TooManyRequestsException::class],
            'Linode broke, not the caller' => [500, ServerException::class],
            'anything else the caller got wrong' => [418, ClientException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $expected): void
    {
        Http::fake([
            'api.linode.com/*' => Http::response(
                ['errors' => [['reason' => 'Something Linode said.']]],
                $status
            ),
        ]);

        $this->expectException($expected);

        Linode::domains()->get(1234);
    }

    #[Test]
    public function an_insufficient_scope_is_a_401_and_still_arrives_as_a_scope_failure(): void
    {
        // The one place in the core package where the exception type deliberately does not
        // follow the status, measured against the live API on 2026-09-12. A valid token
        // calling an endpoint its scopes do not cover answers 401, the same status as a token
        // that is not a token at all - and X-OAuth-Scopes is the only thing that separates
        // them, because Linode can only report a token's own scopes for a token it recognises.
        //
        // That makes it this package's problem rather than only the core's: the discrimination
        // is carried entirely by response HEADERS, so a transport that dropped or rewrote them
        // would turn "widen the token's scopes" into "replace the credential" with nothing to
        // say it had.
        Http::fake([
            'api.linode.com/*' => Http::response(
                ['errors' => [['reason' => 'Your OAuth token is not authorized to use this endpoint.']]],
                401,
                [
                    'X-OAuth-Scopes' => 'domains:read_write',
                    'X-Accepted-OAuth-Scopes' => 'account:read_only',
                ]
            ),
        ]);

        try {
            Linode::account()->get();
            $this->fail('Expected a NotPermittedException.');
        } catch (NotPermittedException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertTrue($e->isScopeFailure());
            $this->assertSame('domains:read_write', (string) $e->heldScopes());
            $this->assertSame('account:read_only', (string) $e->requiredScopes());
        }
    }

    #[Test]
    public function a_401_that_names_no_scopes_is_a_bad_credential(): void
    {
        // The other half of the same header. Linode answers "unknown" for a token it does not
        // recognise, and a stripped header degrades to this case too, which is the
        // conservative reading: replace the credential rather than widen it.
        Http::fake([
            'api.linode.com/*' => Http::response(
                ['errors' => [['reason' => 'Invalid Token']]],
                401,
                ['X-OAuth-Scopes' => 'unknown']
            ),
        ]);

        $this->expectException(NotAuthenticatedException::class);

        Linode::verify();
    }

    #[Test]
    public function a_rejected_field_is_still_readable_on_the_exception(): void
    {
        Http::fake([
            'api.linode.com/*' => Http::response(
                ['errors' => [['field' => 'priority', 'reason' => 'Priority must be 0-255']]],
                400
            ),
        ]);

        try {
            Linode::records()->create(1234, self::record());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['Priority must be 0-255'], $e->reasons());
            $this->assertSame(['priority' => ['Priority must be 0-255']], $e->fieldErrors());
            $this->assertTrue($e->concerns('priority'));
        }
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a WAF challenge, a CDN interstitial and a truncated body are all
        // a 200 with something other than JSON in it. Returned as an empty result they would
        // read as "this account has no zones" everywhere downstream - and code that acts on
        // that answer deletes things.
        Http::fake([
            'api.linode.com/*' => Http::response('<html><body>Down for maintenance</body></html>', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        Linode::domains()->all();
    }

    #[Test]
    public function faking_with_no_arguments_reads_as_an_account_with_no_zones(): void
    {
        // NOT an error, and that is the point of pinning it. Http::fake() with no arguments
        // answers every request with an empty 200, and the core package treats an empty body
        // as an empty response rather than a malformed one - it has to, because a 204 is how
        // Linode answers an unrestricted user's grants.
        //
        // So a fake with a forgotten body is the one failure this package cannot make loud:
        // it reads as "this account has no zones", which is a sentence that gets acted on.
        // Always give a body. The README says so for the same reason.
        Http::fake();

        $this->assertSame([], Linode::domains()->all());
    }
}
