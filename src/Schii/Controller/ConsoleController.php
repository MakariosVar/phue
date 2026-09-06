<?php

declare(strict_types=1);

namespace Phue\Schii\Controller;

use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Schii\Config\SchiiConfig;

/**
 * Class ConsoleController
 *
 * Serves the Schii Single Page Application frontend console interface.
 *
 * @package Phue\Schii\Controller
 */
final class ConsoleController
{
    /**
     * ConsoleController constructor.
     *
     * @param Container $container Dependency Injection container instance.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Render the Schii console UI view component.
     *
     * @route GET /schii
     *
     * @param Request $request Incoming HTTP request instance.
     * @param array   $params  Route parameter mapping passed by Router.
     *
     * @return Response Directly returns the Response instance from ComponentRenderer.
     */
    public function index(Request $request, array $params = []): Response
    {
        /** @var ComponentRenderer $renderer */
        $renderer = $this->container->get("schii.renderer");

        /** @var SchiiConfig $config */
        $config = $this->container->get(SchiiConfig::class);

        return $renderer->render("Console", [
            "request" => $request,
            "params" => $params,
            "apiPrefix" => $config->apiPrefix,
        ]);
    }
}