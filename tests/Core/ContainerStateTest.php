<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\ResettableInterface;

class StatefulService implements ResettableInterface
{
    public array $logs = [];

    public function reset(): void
    {
        $this->logs = [];
    }
}

class ApplicationSingleton
{
    public int $counter = 0;
}

#[\PHPUnit\Framework\Attributes\Group('unit')]
class ContainerStateTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function test_application_scoped_services_persist_across_flushes(): void
    {
        $this->container->singleton(ApplicationSingleton::class);

        /** @var ApplicationSingleton $service1 */
        $service1 = $this->container->get(ApplicationSingleton::class);
        $service1->counter++;

        $this->container->flushScope();

        /** @var ApplicationSingleton $service2 */
        $service2 = $this->container->get(ApplicationSingleton::class);

        $this->assertSame(1, $service2->counter);
        $this->assertSame($service1, $service2);
    }

    public function test_request_scoped_services_are_recreated_after_flush(): void
    {
        $this->container->scoped(StatefulService::class);

        /** @var StatefulService $service1 */
        $service1 = $this->container->get(StatefulService::class);
        $service1->logs[] = 'request_1';

        $this->assertCount(1, $service1->logs);

        // Simulate end of HTTP Request cycle
        $this->container->flushScope();

        /** @var StatefulService $service2 */
        $service2 = $this->container->get(StatefulService::class);

        $this->assertNotSame($service1, $service2);
        $this->assertEmpty($service2->logs);
    }

    public function test_resettable_interface_is_triggered_on_flush(): void
    {
        $this->container->scoped(StatefulService::class);

        /** @var StatefulService $service */
        $service = $this->container->get(StatefulService::class);
        $service->logs[] = 'data';

        $this->container->flushScope();

        // Object state reset before array purge
        $this->assertEmpty($service->logs);
    }
}