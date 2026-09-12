<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Exception;

use Hampel\Linode\Api\Exception\LinodeException;

/**
 * The configuration cannot be turned into a client.
 *
 * Raised rather than letting a half-configured account through, and both cases it covers are
 * raised HERE rather than where they would otherwise surface. A missing token reaches the
 * core package as an empty credential and fails on first use as a 401 - which reads as a
 * revoked token rather than as an unset environment variable. An unusable version or page
 * size is rejected by the core package with a message about an argument, which is accurate
 * and says nothing about where to go and fix it.
 */
final class InvalidConfiguration extends LinodeException
{
    public static function missingToken(string $account): self
    {
        return new self(sprintf(
            'Linode account "%s" has no token. Set it in config/linode.php, or in the '
            . 'environment if the shipped config is in use. Every Linode endpoint needs a '
            . 'credential, so there is no anonymous request to fall back to.',
            $account
        ));
    }

    /**
     * The core package refused the version, page size or base URI.
     *
     * Wrapped rather than passed through, so the message names the configuration rather than
     * an argument - and kept as the previous exception, so the original reason is still
     * readable.
     */
    public static function api(\Throwable $previous): self
    {
        return new self(
            'The linode.version, linode.page_size and linode.base_uri settings do not '
            . 'describe an API this package can talk to. ' . $previous->getMessage(),
            0,
            $previous
        );
    }
}
