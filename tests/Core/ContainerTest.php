<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\Exceptions\ContainerException;
use Phue\Core\Exceptions\NotFoundException;
use Phue\Core\Exceptions\CircularDependencyException;

final class ContainerTest extends TestCase
{
    private Container $container;

    /**
     * Runs BEFORE each test method.
     * Initializes a fresh Container instance to prevent test cross-contamination.
     */
    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /**
     * Tests registering a factory and resolving the expected data structure.
     */
    public function testSetAndGetFactory(): void
    {
        // Register service
        $this->container->set("config", function () {
            return ["env" => "test"];
        });

        // Verify get() returns the expected array
        $this->assertEquals(["env" => "test"], $this->container->get("config"));
    }

    /**
     * Tests the has() method.
     * Should return true if a service exists, false otherwise.
     */
    public function testHasReturnsCorrectBool(): void
    {
        $this->container->set("service", fn() => new \stdClass());

        $this->assertTrue($this->container->has("service"));
        $this->assertFalse($this->container->has("non_existent_service"));
    }

    /**
     * Tests instance caching / singleton behavior.
     * Requests the same service twice and verifies that both references
     * point to the exact same instance in memory.
     */
    public function testGetReturnsSameInstanceCached(): void
    {
        $this->container->set("db", function () {
            return new \stdClass();
        });

        $instance1 = $this->container->get("db");
        $instance2 = $this->container->get("db");

        // assertSame checks for strict identity (===) in memory
        $this->assertSame($instance1, $instance2);
    }

    /**
     * Tests make() method to ensure fresh non-singleton instance resolution.
     */
    public function testMakeReturnsNewInstanceEveryTime(): void
    {
        $this->container->set("db", function () {
            return new \stdClass();
        });

        $instance1 = $this->container->make("db");
        $instance2 = $this->container->make("db");

        $this->assertNotSame($instance1, $instance2);
    }

    /**
     * Tests error handling.
     * Expects a NotFoundException when attempting to resolve an unregistered service.
     */
    public function testGetThrowsExceptionWhenServiceNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(
            "Missing factory or class for service: 'unknown_service'",
        );

        // Executing this should trigger the exception
        $this->container->get("unknown_service");
    }

    /**
     * Tests nested dependency resolution (DI).
     * Verifies that a factory can receive the container instance
     * and request another service from it.
     */
    public function testResolvesNestedDependencies(): void
    {
        // 1. Register a dependency ('config')
        $this->container->set("config", fn() => ["db_host" => "127.0.0.1"]);

        // 2. Register a dependent service ('db') using the injected Container ($c)
        $this->container->set("db", function (Container $c) {
            $config = $c->get("config");
            return new \stdClass();
        });

        // 3. Resolve the dependent service to confirm DI works as expected
        $this->assertInstanceOf(\stdClass::class, $this->container->get("db"));
    }

    /**
     * Tests automatic constructor dependency resolution (Autowiring).
     */
    public function testAutowiresClassDependenciesAutomatically(): void
    {
        // PhueAutowireTarget depends on PhueAutowireDependency
        $instance = $this->container->get(PhueAutowireTarget::class);

        $this->assertInstanceOf(PhueAutowireTarget::class, $instance);
        $this->assertInstanceOf(
            PhueAutowireDependency::class,
            $instance->dependency,
        );
    }

    /**
     * Test 1: Class without a __construct method.
     */
    public function testAutowiresClassWithoutConstructor(): void
    {
        $instance = $this->container->get(NoConstructorService::class);
        $this->assertInstanceOf(NoConstructorService::class, $instance);
    }

    /**
     * Test 2: Concrete dependency resolution + Scalar parameter with default value.
     */
    public function testAutowiresConcreteDependencyAndDefaultScalar(): void
    {
        $instance = $this->container->get(ServiceWithScalarDefault::class);

        $this->assertInstanceOf(ServiceWithScalarDefault::class, $instance);
        $this->assertInstanceOf(
            NoConstructorService::class,
            $instance->dependency,
        );
        $this->assertEquals("localhost", $instance->host);
    }

    /**
     * Test 3: Interface binding via set() fallback during autowiring.
     */
    public function testAutowiresInterfaceBindingViaSet(): void
    {
        // Bind interface to concrete implementation manually
        $this->container->set(LoggerInterface::class, fn() => new FileLogger());

        $instance = $this->container->get(
            ServiceWithInterfaceDependency::class,
        );

        $this->assertInstanceOf(
            ServiceWithInterfaceDependency::class,
            $instance,
        );
        $this->assertInstanceOf(FileLogger::class, $instance->logger);
    }

    /**
     * Test 4: Failure case — Unbound interface throws ContainerException because it is not instantiable.
     */
    public function testThrowsExceptionForUnboundInterface(): void
    {
        $this->expectException(ContainerException::class);
        $this->container->get(LoggerInterface::class);
    }

    /**
     * Test 5: Failure case — Missing scalar default value throws ContainerException.
     */
    public function testThrowsExceptionForMissingScalarDefault(): void
    {
        $this->expectException(ContainerException::class);
        $this->container->get(ServiceWithMissingScalarDefault::class);
    }

    /**
     * Test 6: Failure case — Circular dependency loop detection.
     */
    public function testDetectsCircularDependencies(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->container->get(CircularClassA::class);
    }

    /**
     * Test 7: Nullable unbound interface falls back to null.
     */
    public function testAutowireResolvesUnboundNullableInterfaceToNull(): void
    {
        $service = $this->container->get(ServiceWithUnboundNullableInterface::class);
        $this->assertInstanceOf(ServiceWithUnboundNullableInterface::class, $service);
        $this->assertNull($service->logger);
    }

    /**
     * Test 8: Nullable scalar falls back to null.
     */
    public function testAutowireResolvesNullableScalarToNull(): void
    {
        $service = $this->container->get(ServiceWithNullableScalar::class);
        $this->assertInstanceOf(ServiceWithNullableScalar::class, $service);
        $this->assertNull($service->name);
    }
}

/* =====================================================================
 * HELPER CLASSES FOR AUTOWIRING TESTS
 * (Must be defined OUTSIDE the ContainerTest class)
 * ===================================================================== */

class PhueAutowireDependency {}
class NoConstructorService {}

class ServiceWithScalarDefault
{
    public function __construct(
        public NoConstructorService $dependency,
        public string $host = "localhost",
    ) {}
}

interface LoggerInterface {}

class FileLogger implements LoggerInterface {}

class ServiceWithInterfaceDependency
{
    public function __construct(public LoggerInterface $logger) {}
}

class ServiceWithMissingScalarDefault
{
    public function __construct(public string $unboundApiKey) {}
}

class ServiceWithUnboundNullableInterface
{
    public function __construct(public ?LoggerInterface $logger) {}
}

class ServiceWithNullableScalar
{
    public function __construct(public ?string $name) {}
}

class PhueAutowireTarget
{
    public function __construct(public PhueAutowireDependency $dependency) {}
}

class CircularClassA
{
    public function __construct(public CircularClassB $b) {}
}

class CircularClassB
{
    public function __construct(public CircularClassA $a) {}
}

