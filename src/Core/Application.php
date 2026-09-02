<?php

namespace Phue\Core;

use Phue\Core\Routing\Router;

class Application {
    private Container $container;
    private Router $router;
    private EventDispatcher $events;
    private array $modules = [];

    public function __construct(array $config) {
        $this->container = new Container($config);
        $this->router = new Router();
        $this->events = new EventDispatcher();
        $this->loadModules($config['modules'] ?? []);
    }

    public function handle(Request $request): Response {
        // Middleware pipeline → Router → Controller → Response
    }
}
