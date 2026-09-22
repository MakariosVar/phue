<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Controller;

use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\UI\ComponentRenderer;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Schii\Config\SchiiConfig;

/**
 * Class ConsoleController
 *
 * Serves the Schii Single Page Application frontend console interface.
 *
 * @package Phue\Modules\Schii\Controller
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
     * @return Response Directly returns the Response instance from ComponentRenderer or redirect.
     */
    public function index(Request $request, array $params = []): Response
    {
        $authManager = null;
        if ($this->container->has(AuthManager::class)) {
            $authManager = $this->container->get(AuthManager::class);
        } elseif ($this->container->has('auth.manager')) {
            $authManager = $this->container->get('auth.manager');
        } elseif ($this->container->has('auth')) {
            $authManager = $this->container->get('auth');
        }

        if ($authManager instanceof AuthManager) {
            $user = $authManager->authenticate($request);
            if ($user === null) {
                return new Response('', 302, ['Location' => '/login']);
            }
        }

        /** @var ComponentRenderer $renderer */
        $renderer = $this->container->get("schii.renderer");

        /** @var SchiiConfig $config */
        $config = $this->container->get(SchiiConfig::class);

        return $renderer->render("Schii", [
            "request" => $request,
            "params" => $params,
            "apiPrefix" => $config->apiPrefix,
        ]);
    }
}