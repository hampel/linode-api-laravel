<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Laravel\Tests;

use Hampel\Linode\Api\Client;
use Hampel\Linode\Api\Laravel\Facades\Linode;
use Hampel\Linode\Api\Laravel\LinodeManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The facade's @method annotations are the only types a call through it has.
 *
 * Linode::domains() goes through the manager's __call() and returns mixed; what makes
 * `Linode::domains()->all()` analysable is the annotation, not the code. So an accessor added
 * to the core package's Client in a later release is a call that works at runtime and loses
 * its type silently - the failure mode a facade is most prone to, and the one nothing else here
 * would report. The core package is 0.x and expected to grow accessors, which makes this
 * cheaper to have from the start than to add after the first one is missed.
 *
 * Deliberately not a Testbench test: it reads classes, boots nothing, and is quicker to reach
 * for when the core package is upgraded.
 */
final class FacadeConformanceTest extends BaseTestCase
{
    #[Test]
    public function every_client_method_is_annotated_on_the_facade(): void
    {
        $missing = array_diff($this->clientMethods(), $this->annotatedMethods());

        $this->assertSame([], array_values($missing), sprintf(
            'The core package has %s that the facade does not annotate. Add a @method line for '
            . 'each to %s, or calls through the facade lose their return type.',
            implode(', ', $missing),
            Linode::class
        ));
    }

    #[Test]
    public function the_facade_annotates_nothing_that_no_longer_exists(): void
    {
        $known = array_merge($this->clientMethods(), $this->managerMethods());
        $stale = array_diff($this->annotatedMethods(), $known);

        $this->assertSame([], array_values($stale), sprintf(
            'The facade annotates %s, which neither %s nor %s has.',
            implode(', ', $stale),
            Client::class,
            LinodeManager::class
        ));
    }

    #[Test]
    public function every_annotated_return_type_resolves_to_a_real_class(): void
    {
        // A @method line naming a class that does not exist is invisible at runtime and
        // silently useless to static analysis, which is the same failure as not having one.
        $doc = (string) (new ReflectionClass(Linode::class))->getDocComment();

        preg_match_all('/@method static ([^ ]+) /', $doc, $matches);

        foreach ($matches[1] as $type) {
            if (in_array($type, ['string', 'int', 'bool', 'void', 'mixed'], true) || str_contains($type, '<')) {
                continue;
            }

            $this->assertTrue(
                class_exists($this->resolve($type)) || interface_exists($this->resolve($type)),
                sprintf('The facade annotates a return type that does not exist: %s', $type)
            );
        }
    }

    #[Test]
    public function the_manager_does_not_shadow_a_client_method(): void
    {
        // The reason the named-account accessor is client() and not account(): the manager
        // forwards unknown calls to the default client, so a method on both is a method whose
        // meaning depends on which class you thought you were calling. Client::account() is
        // Linode's billing endpoint and would be unreachable through the facade.
        $shadowed = array_intersect($this->managerMethods(), $this->clientMethods());

        $this->assertSame([], array_values($shadowed), sprintf(
            '%s declares %s, which %s also has - so the facade cannot reach the client\'s.',
            LinodeManager::class,
            implode(', ', $shadowed),
            Client::class
        ));
    }

    /**
     * @return list<string>
     */
    private function annotatedMethods(): array
    {
        $doc = (string) (new ReflectionClass(Linode::class))->getDocComment();

        preg_match_all('/@method static [^ ]+ ([A-Za-z0-9_]+)\(/', $doc, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function clientMethods(): array
    {
        return $this->publicMethods(Client::class);
    }

    /**
     * @return list<string>
     */
    private function managerMethods(): array
    {
        return array_values(array_diff($this->publicMethods(LinodeManager::class), ['__call']));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function publicMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isConstructor() && ! $method->isStatic()) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Annotations are written short, against the facade's own imports.
     */
    private function resolve(string $type): string
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(Linode::class))->getFileName());

        preg_match_all('/^use ([^;]+);$/m', $source, $matches);

        foreach ($matches[1] as $import) {
            if (str_ends_with($import, '\\' . $type)) {
                return $import;
            }
        }

        return $type;
    }
}
