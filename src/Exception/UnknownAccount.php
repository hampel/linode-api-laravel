<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Exception;

use Hampel\Linode\Api\Exception\LinodeException;

/**
 * An account was asked for by a name that is not in the configuration.
 *
 * Extends the core package's base exception, so an application already catching
 * LinodeException - or ExceptionInterface - catches a misconfigured account name alongside
 * every other way a call can fail.
 */
final class UnknownAccount extends LinodeException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'Linode account "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No accounts are configured; add one under linode.accounts.'
                : 'Configured accounts: ' . implode(', ', $configured) . '.'
        ));
    }
}
