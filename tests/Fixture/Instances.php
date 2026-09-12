<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests\Fixture;

use Hampel\Linode\Api\Endpoint\Endpoint;

/**
 * An endpoint the core package does not wrap, reached through this package's container.
 *
 * Linode has some three hundred paths and the core package wraps a dozen, so most consumers
 * will write one of these. The point of the fixture is that nothing about it is registered:
 * the class IS the registration, and `Linode::client()->endpoint(Instances::class)` constructs
 * it over the same faked transport as everything else.
 */
final class Instances extends Endpoint
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return iterator_to_array(
            $this->apiEach('linode/instances', static fn (array $row): array => $row),
            false
        );
    }
}
